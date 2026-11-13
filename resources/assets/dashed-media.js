(() => {
    if (window.dashedMedia) {
        return;
    }

    // Eigen typen, zodat slepen binnen de pagina niet botst met bestanden van het bureaublad.
    const DRAG_ITEMS = 'application/x-dashed-media-items';
    const DRAG_FOLDER = 'application/x-dashed-media-folder';
    const MAX_PARALLEL_UPLOADS = 3;

    const stored = {
        get(key, fallback) {
            try {
                const value = window.localStorage.getItem('dashed-media.' + key);

                return value === null ? fallback : JSON.parse(value);
            } catch (e) {
                return fallback;
            }
        },
        set(key, value) {
            try {
                window.localStorage.setItem('dashed-media.' + key, JSON.stringify(value));
            } catch (e) {
                // Privévenster of geblokkeerde opslag: dan onthouden we het niet.
            }
        },
    };

    const notify = (title, status = 'success') => {
        if (! window.FilamentNotification || ! title) {
            return;
        }

        new window.FilamentNotification().title(title)[status]().send();
    };

    const fill = (text, values) => Object.keys(values).reduce((out, key) => out.split(':' + key).join(String(values[key])), String(text || ''));

    // Fase 9b: een poster van een video, gemaakt in de browser (de server
    // heeft geen ffmpeg). Het lokale bestand in een onzichtbare video, één
    // beeld naar een canvas, als jpeg. Lukt het niet binnen een paar
    // seconden (codec niet ondersteund), dan null: de upload gaat gewoon door.
    const videoPoster = (file, timeout = 4000) => new Promise((resolve) => {
        let done = false;
        let url = null;
        let video = null;
        let timer = null;

        const finish = (blob) => {
            if (done) {
                return;
            }

            done = true;
            clearTimeout(timer);

            try {
                if (video) {
                    video.removeAttribute('src');
                    video.load();
                }
            } catch (e) {
                // Niets op te ruimen.
            }

            if (url) {
                URL.revokeObjectURL(url);
            }

            resolve(blob || null);
        };

        const draw = () => {
            try {
                const width = video.videoWidth;
                const height = video.videoHeight;

                if (! width || ! height) {
                    finish(null);

                    return;
                }

                const scale = Math.min(1, 1280 / width);
                const canvas = document.createElement('canvas');

                canvas.width = Math.max(2, Math.round(width * scale));
                canvas.height = Math.max(2, Math.round(height * scale));
                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                canvas.toBlob((blob) => finish(blob && blob.size > 100 ? blob : null), 'image/jpeg', 0.82);
            } catch (e) {
                finish(null);
            }
        };

        timer = setTimeout(() => finish(null), timeout);

        try {
            if (! file || ! String(file.type || '').startsWith('video/')) {
                finish(null);

                return;
            }

            video = document.createElement('video');
            url = URL.createObjectURL(file);
            video.muted = true;
            video.playsInline = true;
            video.preload = 'auto';
            video.addEventListener('error', () => finish(null));
            video.addEventListener('seeked', draw, { once: true });
            video.addEventListener('loadeddata', () => {
                // Ongeveer tien procent van de duur, hooguit drie seconden in.
                const at = Math.min(3, Math.max(0, (Number.isFinite(video.duration) ? video.duration : 0) * 0.1));

                if (at > 0.05) {
                    video.currentTime = at;
                } else {
                    draw();
                }
            }, { once: true });
            video.src = url;
        } catch (e) {
            finish(null);
        }
    });

    // Stuurt de poster als tweede upload mee, onder de sleutel van het
    // bestand met een p erachter. Geeft die sleutel terug, of null.
    const uploadPoster = (wire, key, posterOf) => Promise.resolve(posterOf).then((blob) => new Promise((resolve) => {
        if (! blob) {
            resolve(null);

            return;
        }

        const posterKey = key + 'p';

        try {
            wire.upload('uploads.' + posterKey, new File([blob], 'poster.jpg', { type: 'image/jpeg' }), () => resolve(posterKey), () => resolve(null));
        } catch (e) {
            resolve(null);
        }
    })).catch(() => null);

    const emptyFilters = () => ({
        folder: null,
        include_subfolders: false,
        smart: null,
        search: '',
        type: '',
        tag: [],
        source: '',
        min_width: '',
        min_height: '',
        date_from: '',
        date_to: '',
        sort: 'newest',
    });

    const isTyping = (event) => {
        const el = event.target;

        return !! el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
    };

    const dragTypes = (event) => Array.from(event.dataTransfer?.types || []);

    const browser = () => {
        // Buiten de reactieve staat: Alpine verpakt die in een Proxy, en een
        // File of IntersectionObserver werkt daar niet doorheen.
        const pendingFiles = new Map();
        // Een upload die een duplicaat bleek: het bestand blijft hier tot de
        // lijst gewist wordt, voor "toch als nieuw bestand toevoegen".
        const duplicateFiles = new Map();
        let observer = null;
        let listSeq = 0;
        let detailSeq = 0;
        let activeUploads = 0;
        let tagTimer = null;
        // Vastgelegd bij de start en niet per aanroep via this.$wire of
        // this.$refs: Alpine zoekt die op vanaf het element dat de aanroep
        // deed, en een menuknop of invoerveld is tegen die tijd vaak al weg.
        let root = null;
        let wire = null;
        let observerRoot = undefined;
        let lastFocus = null;
        let onResize = null;
        let stackedQuery = null;
        let onStacked = null;
        let offUploadErrored = null;
        // De bron in de bewerk-dialoog en de timer van de statusregel: buiten de reactieve staat.
        let editImage = null;
        let runTimer = null;
        let movingTimer = null;
        let onVisible = null;
        // Fase 11: de timer waarmee het paneel van de AI-bron de stand opvraagt, en een teller die verouderde antwoorden herkent.
        let sourceTimer = null;
        let sourceSeq = 0;
        // Waarom de uploadroute een bestand weigerde, per uploadnaam.
        const refusals = new Map();
        const ref = (name) => root?.querySelector('[x-ref="' + name + '"]') || null;

        return {
            L: {},
            config: {},
            manage: true,
            // Kies-modus (het keuzevenster van een mediaveld): wat het veld
            // vroeg, het actieve tabblad en de volgorde waarin er gekozen is.
            pick: null,
            PL: {},
            // Fase 10: het sitefilter. '' is alle sites, '__record' de sites
            // van het record dat bewerkt wordt (keuzevenster), anders een site-id.
            siteChoice: '',
            siteOptions: [],
            recordSites: [],
            hasAccess: false,
            pickTab: 'library',
            pickFolder: null,
            pickOrder: [],
            chosen: {},
            filters: emptyFilters(),
            items: [],
            page: 0,
            hasMore: false,
            loading: true,
            failed: false,
            total: null,
            tree: { folders: [], rootCount: 0, allCount: 0, trashCount: 0, hasDuplicates: false, usageReady: false },
            trashedFolders: [],
            expanded: {},
            treeOpen: false,
            folderMenu: null,
            renaming: null,
            renameValue: '',
            dropFolder: null,
            gridDrop: false,
            selected: {},
            selectedCount: 0,
            selectionNote: '',
            anchor: null,
            focusIndex: 0,
            // Net als bij het dialoogvenster: de inhoud blijft staan, detailOpen bepaalt of het paneel er is.
            detail: {},
            detailOpen: false,
            detailLoading: false,
            form: { title: '', alt: '', caption: '' },
            // De talen van de inhoud en de taal die het detailpaneel nu bewerkt.
            locales: [],
            textLocale: null,
            saved: {},
            tagInput: '',
            tagSuggestions: [],
            view: 'grid',
            moreOpen: false,
            tagMenu: false,
            tagSearch: '',
            tagOptions: [],
            tagNames: {},
            uploads: [],
            // De inhoud blijft na het sluiten staan: zo evalueert geen enkele
            // binding in het venster nog tegen null terwijl Alpine het weghaalt.
            dialog: { type: null },
            dialogOpen: false,
            busy: false,
            touch: false,
            live: '',
            // Fase 7: het focuspunt van het open bestand en de stand van de pomp.
            focal: { x: 50, y: 50 },
            focalDirty: false,
            run: null,
            runFailed: null,
            missingFormats: false,
            // Fase 11: de externe bronnen. sourceKey is de bron waarvan het paneel open staat.
            sources: [],
            SL: {},
            sourceKey: null,
            source: {},
            sourceForm: { url: '', query: '', orientation: '', prompt: '', ratio: '1:1' },
            sourceResults: [],
            sourcePage: 0,
            sourceHasMore: false,
            sourceQuery: '',
            sourceBusy: false,
            sourceMessage: '',
            sourceState: '',
            sourceReference: null,

            init() {
                root = this.$el;
                wire = this.$wire;

                try {
                    this.config = JSON.parse(this.$el.dataset.dashedMedia || '{}');
                } catch (e) {
                    this.config = {};
                }

                this.L = this.config.labels || {};
                this.sources = Array.isArray(this.config.sources) ? this.config.sources : [];
                this.SL = this.config.sourceLabels || {};
                this.locales = Array.isArray(this.config.locales) ? this.config.locales : [];
                this.textLocale = this.locales.length ? this.locales[0].id : null;
                this.manage = this.config.mode !== 'pick';

                if (! this.manage) {
                    this.pick = Object.assign({ accept: [], multiple: false, max: null, folder: null, selected: [], remember: null, upload: false }, this.config.pick || {});
                    this.PL = this.config.pickLabels || {};
                    (this.pick.selected || []).forEach((id) => { this.chosen[id] = true; });

                    // De laatst gebruikte map van dit veld, anders zijn standaardmap.
                    const remembered = this.pick.remember ? stored.get('pick.' + this.pick.remember, undefined) : undefined;

                    this.filters.folder = typeof remembered === 'number' || remembered === 'root' || remembered === null
                        ? remembered
                        : (this.pick.folder || null);
                    this.pickFolder = this.filters.folder;

                    // Het veld vroeg om meteen een bron te openen (de knop "Genereer met AI").
                    if (this.pick.source && this.sources.some((entry) => entry.key === this.pick.source)) {
                        this.$nextTick(() => this.openSource(this.pick.source));
                    }
                }

                // Fase 10. Alleen bij meer dan één site stuurt de server sites mee.
                this.hasAccess = !! this.config.access;
                this.siteOptions = Array.isArray(this.config.sites) ? this.config.sites : [];

                if (this.siteOptions.length) {
                    const known = this.siteOptions.map((site) => site.id);

                    if (this.pick) {
                        this.recordSites = (this.pick.sites || []).filter((id) => known.includes(id));
                        this.siteChoice = this.recordSites.length > 1 ? '__record' : (this.recordSites[0] || '');
                    } else {
                        this.siteChoice = known.includes(this.config.activeSite) ? this.config.activeSite : '';
                    }
                }
                this.view = stored.get('view', 'grid') === 'list' ? 'list' : 'grid';
                this.expanded = stored.get('expanded', {}) || {};
                this.touch = window.matchMedia && window.matchMedia('(hover: none)').matches;

                // $on geeft de functie terug die de luisteraar weer weghaalt:
                // zonder die aanroep komt er bij elke opening van het
                // keuzevenster een luisteraar bij.
                offUploadErrored = wire.$on('dashed-media-upload-errored', (detail) => {
                    refusals.set(detail.name, { message: detail.message || '', refused: !! detail.refused });
                });

                this.$nextTick(() => this.fit());

                let resizeTimer = null;

                onResize = () => {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(() => this.fit(), 100);
                };

                window.addEventListener('resize', onResize);

                // De overgang naar gestapeld meteen, niet vertraagd: tot de
                // waarnemer gewisseld is ziet de oude de schildwacht altijd
                // in beeld en laadt hij pagina na pagina.
                stackedQuery = window.matchMedia('(max-width: 900px)');
                onStacked = () => this.fit();
                stackedQuery.addEventListener?.('change', onStacked);

                // Twee aanroepen in dezelfde tik: Livewire bundelt ze in één verzoek.
                this.reload();
                this.loadTree();

                if (this.manage) {
                    this.loadRunStatus();
                    onVisible = () => {
                        if (! document.hidden && this.run) {
                            this.loadRunStatus();
                        }
                    };
                    document.addEventListener('visibilitychange', onVisible);
                }

                // Een mediaveld linkt hierheen met ?item=<id>: dat item staat dan open.
                if (this.manage && Number.isInteger(this.config.openItem) && this.config.openItem > 0) {
                    this.setSelection([this.config.openItem]);
                }
            },

            destroy() {
                if (typeof offUploadErrored === 'function') {
                    offUploadErrored();
                }

                offUploadErrored = null;
                // Het venster gaat dicht: niet verder vragen naar de stand van een AI-aanvraag.
                clearTimeout(sourceTimer);
                sourceSeq++;
                clearTimeout(runTimer);
                clearTimeout(movingTimer);
                editImage = null;

                if (onVisible) {
                    document.removeEventListener('visibilitychange', onVisible);
                }

                window.removeEventListener('resize', onResize);
                stackedQuery?.removeEventListener?.('change', onStacked);
                observer?.disconnect();
            },

            // Meet de hoogte in plaats van hem te schatten: van de bovenkant
            // van het component tot de onderkant van het venster, min de
            // ruimte die de omringende containers onder zich laten. Gestapeld
            // (smal) is er geen vaste hoogte en scrolt de pagina zelf.
            fit() {
                const stacked = window.matchMedia('(max-width: 900px)').matches;

                if (stacked || this.pick) {
                    // In het keuzevenster bepaalt de opmaak de hoogte.
                    root.style.removeProperty('--dm-height');
                } else {
                    let below = 0;

                    for (let node = root.parentElement; node && node !== document.documentElement; node = node.parentElement) {
                        const style = window.getComputedStyle(node);

                        below += (parseFloat(style.paddingBottom) || 0) + (parseFloat(style.marginBottom) || 0) + (parseFloat(style.borderBottomWidth) || 0);
                    }

                    const top = root.getBoundingClientRect().top + window.scrollY;

                    root.style.setProperty('--dm-height', Math.max(420, Math.floor(window.innerHeight - top - below)) + 'px');
                }

                this.watchSentinel(stacked ? null : ref('scroll'));
            },

            // Gestapeld scrolt het venster, anders het raster: de waarnemer
            // moet tegen de juiste van de twee meten.
            watchSentinel(scrollRoot) {
                if (! ('IntersectionObserver' in window) || ! ref('sentinel') || (observer && observerRoot === scrollRoot)) {
                    return;
                }

                observer?.disconnect();
                observerRoot = scrollRoot;
                observer = new IntersectionObserver((entries) => {
                    if (entries.some((entry) => entry.isIntersecting)) {
                        this.loadMore();
                    }
                }, { root: scrollRoot, rootMargin: '500px 0px' });
                observer.observe(ref('sentinel'));
            },

            // ---------- Lijst ----------

            cleanFilters() {
                const out = {};

                Object.keys(this.filters).forEach((key) => {
                    const value = this.filters[key];

                    if (value === null || value === '' || value === false || (Array.isArray(value) && ! value.length)) {
                        return;
                    }

                    out[key] = Array.isArray(value) ? [...value] : value;
                });

                const sites = this.activeSites();

                if (sites.length) {
                    out.site = sites;
                }

                return out;
            },

            // ---------- Fase 10: rechten, sites, privé ----------

            get can() {
                return this.config.can || {};
            },

            get canEdit() {
                return this.manage && !! this.can.edit;
            },

            get canDelete() {
                return this.manage && !! this.can.delete;
            },

            get canFolders() {
                return this.manage && !! this.can.folders;
            },

            activeSites() {
                if (! this.siteOptions.length || this.siteChoice === '') {
                    return [];
                }

                return this.siteChoice === '__record' ? [...this.recordSites] : [this.siteChoice];
            },

            setSite(value) {
                this.siteChoice = value;
                this.reload();
            },

            // Een map zonder eigen sites hoort bij alle sites.
            folderInSites(node) {
                const wanted = this.activeSites();

                return ! wanted.length || ! Array.isArray(node.sites) || node.sites.some((id) => wanted.includes(id));
            },

            movingTitle(count) {
                return fill(this.L.movingCount, { count: count || 0 });
            },

            async retryRelocation() {
                await this.busyWhile(async () => {
                    this.applyTree(await wire.retryRelocation());
                });
            },

            async askFolderSettings(id) {
                this.folderMenu = null;

                const result = await wire.loadFolderSettings(id);

                if (! result || ! result.ok) {
                    return;
                }

                const settings = result.settings;

                this.openDialog({
                    type: 'settings',
                    folderId: id,
                    name: settings.name,
                    private: !! settings.private || !! settings.inherited_private,
                    ownPrivate: !! settings.private,
                    inheritedPrivate: !! settings.inherited_private,
                    sites: Array.isArray(settings.sites) ? [...settings.sites] : [],
                    siteOptions: settings.site_options || [],
                    inheritedSites: settings.inherited_sites || '',
                });
            },

            toggleDialogSite(id) {
                const sites = this.dialog.sites.filter((site) => site !== id);

                if (sites.length === this.dialog.sites.length) {
                    sites.push(id);
                }

                this.dialog.sites = sites;
            },

            async confirmFolderSettings() {
                const dialog = this.dialog;
                // Onder een privé-map is het vinkje geërfd: de eigen keuze van de map blijft wat hij was.
                const settings = { private: dialog.inheritedPrivate ? dialog.ownPrivate : !! dialog.private, sites: [...dialog.sites] };
                let ask = null;

                await this.busyWhile(async () => {
                    ask = await this.saveFolderSettings(dialog.folderId, settings, false);
                });

                this.closeDialog();

                if (ask) {
                    this.$nextTick(ask);
                }
            },

            async saveFolderSettings(id, settings, confirmed) {
                const result = await wire.saveFolderSettings(id, settings, confirmed);

                if (result && result.confirm) {
                    return () => this.askVisibility(result.confirm, () => this.saveFolderSettings(id, settings, true));
                }

                if (this.applyTree(result)) {
                    notify(this.L.saved);
                    this.reload();
                    this.watchMoving();
                }

                return null;
            },

            /**
             * De bevestiging voor alles wat bestanden van kant laat wisselen.
             * Naar privé: de site toont ze daarna niet meer. Naar publiek:
             * iedereen met het adres kan ze dan openen.
             */
            askVisibility(confirm, run) {
                const lines = [];
                const toPrivate = confirm.to_private || 0;
                const toPublic = confirm.to_public || 0;

                if (toPrivate) {
                    lines.push(fill(this.L.toPrivateBody, { count: toPrivate }));

                    if (confirm.in_use === null || confirm.in_use === undefined) {
                        lines.push(this.L.toPrivateUnknown);
                    } else if (confirm.in_use > 0) {
                        lines.push(fill(this.L.toPrivateInUse, { count: confirm.in_use, places: confirm.places || 0 }));
                    } else {
                        lines.push(this.L.toPrivateUnused);
                    }
                }

                if (toPublic || confirm.folders_to_public) {
                    lines.push(toPublic ? fill(this.L.toPublicBody, { count: toPublic }) : this.L.toPublicEmpty);
                }

                this.openDialog({
                    type: 'confirm',
                    title: toPrivate && ! toPublic ? this.L.toPrivateTitle : (toPublic && ! toPrivate ? this.L.toPublicTitle : this.L.visibilityTitle),
                    body: lines.shift() || '',
                    lines,
                    caveat: toPrivate ? this.L.toPrivateCaveat : '',
                    action: this.L.confirm,
                    danger: true,
                    run,
                });
            },

            // Zolang er bestanden onderweg zijn de boom af en toe verversen, alleen op de beheerpagina.
            watchMoving() {
                clearTimeout(movingTimer);

                if (! this.manage || ! this.tree.moving) {
                    return;
                }

                movingTimer = setTimeout(async () => {
                    if (root && root.isConnected) {
                        await this.loadTree();
                    }
                }, 6000);
            },

            reload() {
                listSeq++;
                this.items = [];
                this.page = 0;
                this.hasMore = false;
                this.total = null;
                this.failed = false;

                // In het keuzevenster blijft de selectie staan als je van map wisselt.
                if (! this.pick) {
                    this.clearSelection();
                }

                return this.fetchPage(1, listSeq);
            },

            loadMore() {
                if (this.loading || ! this.hasMore) {
                    return;
                }

                this.fetchPage(this.page + 1, listSeq);
            },

            async fetchPage(page, token) {
                this.loading = true;

                let result;

                try {
                    result = await wire.loadItems(this.cleanFilters(), page);
                } catch (e) {
                    if (token === listSeq) {
                        this.loading = false;
                        this.failed = true;
                    }

                    return;
                }

                // De gebruiker typte of klikte intussen door: dit antwoord hoort bij een oud filter.
                if (token !== listSeq) {
                    return;
                }

                const known = {};
                this.items.forEach((item) => { known[item.id] = true; });
                this.items.push(...(result.items || []).filter((item) => ! known[item.id]));
                this.page = result.page;
                this.hasMore = !! result.hasMore;
                this.loading = false;

                if (result.total !== null && result.total !== undefined) {
                    this.total = result.total;
                }

                // De waarnemer meldt alleen een verandering; staat de schildwacht
                // na het laden nog in beeld, dan moet hij opnieuw afgaan.
                this.$nextTick(() => {
                    if (observer && ref('sentinel')) {
                        observer.unobserve(ref('sentinel'));
                        observer.observe(ref('sentinel'));
                    }
                });
            },

            setFilter(key, value) {
                this.filters[key] = value;
                this.reload();
            },

            toggleTagFilter(id) {
                const tags = this.filters.tag.filter((tag) => tag !== id);

                if (tags.length === this.filters.tag.length) {
                    tags.push(id);
                }

                this.setFilter('tag', tags);
            },

            get activeFilterCount() {
                const f = this.filters;

                return ['search', 'type', 'source', 'min_width', 'min_height', 'date_from', 'date_to'].filter((key) => f[key] !== '' && f[key] !== null).length
                    + (f.tag.length ? 1 : 0)
                    + (f.smart === 'favorites' && f.folder !== null ? 1 : 0);
            },

            clearFilters() {
                const keep = { folder: this.filters.folder, include_subfolders: this.filters.include_subfolders, sort: this.filters.sort };

                if (this.filters.folder === null && this.filters.smart) {
                    keep.smart = this.filters.smart;
                }

                this.filters = Object.assign(emptyFilters(), keep);
                this.reload();
            },

            setView(view) {
                this.view = view;
                stored.set('view', view);
            },

            // ---------- Boom ----------

            async loadTree() {
                try {
                    this.tree = await wire.loadTree();
                } catch (e) {
                    // De lijst werkt ook zonder boom; de volgende mutatie haalt hem opnieuw.
                    return;
                }

                this.watchMoving();

                if (! this.pick || typeof this.filters.folder !== 'number') {
                    return;
                }

                const trail = this.folderTrail(this.filters.folder);

                if (trail.length) {
                    trail.slice(0, -1).forEach((step) => { this.expanded[step.id] = true; });
                } else {
                    // De onthouden map bestaat niet meer.
                    const fallback = this.pick.folder && this.pick.folder !== this.filters.folder && this.folderTrail(this.pick.folder).length ? this.pick.folder : null;

                    this.openFolder(fallback);
                }
            },

            flatten(onlyOpen) {
                const rows = [];
                const walk = (nodes, depth) => (nodes || []).forEach((node) => {
                    const open = !! this.expanded[node.id];

                    // Fase 10: met een sitefilter verdwijnt een map (en alles eronder) die niet bij die site hoort.
                    if (! this.folderInSites(node)) {
                        return;
                    }

                    rows.push({ id: node.id, name: node.name, parent_id: node.parent_id, depth, total: node.total, hasChildren: (node.children || []).length > 0, open, private: !! node.private, moving: node.moving || 0 });

                    if (open || ! onlyOpen) {
                        walk(node.children, depth + 1);
                    }
                });

                walk(this.tree.folders, 0);

                return rows;
            },

            get treeRows() {
                return this.flatten(true);
            },

            get allFolders() {
                return this.flatten(false);
            },

            folderTrail(id) {
                const trail = [];
                const find = (nodes, path) => (nodes || []).some((node) => {
                    const next = [...path, { id: node.id, name: node.name }];

                    if (node.id === id) {
                        trail.push(...next);

                        return true;
                    }

                    return find(node.children, next);
                });

                find(this.tree.folders, []);

                return trail;
            },

            get crumbs() {
                return typeof this.filters.folder === 'number' ? this.folderTrail(this.filters.folder) : [];
            },

            get locationLabel() {
                if (this.filters.folder === 'root') {
                    return this.L.noFolder;
                }

                if (this.filters.folder === null && this.filters.smart) {
                    return (this.L.smart || {})[this.filters.smart] || '';
                }

                return this.L.allFiles;
            },

            toggleFolder(id) {
                if (this.expanded[id]) {
                    delete this.expanded[id];
                } else {
                    this.expanded[id] = true;
                }

                stored.set('expanded', this.expanded);
            },

            openFolder(folder) {
                this.filters.folder = folder;
                this.filters.smart = null;
                this.treeOpen = false;
                this.folderMenu = null;

                if (typeof folder === 'number') {
                    this.folderTrail(folder).slice(0, -1).forEach((step) => { this.expanded[step.id] = true; });
                    stored.set('expanded', this.expanded);
                }

                if (this.pick) {
                    this.pickTab = 'library';
                    this.pickFolder = folder;

                    if (this.pick.remember) {
                        stored.set('pick.' + this.pick.remember, folder);
                    }
                }

                this.reload();
            },

            openSmart(key) {
                if (this.pick) {
                    this.pickTab = key === 'recent' || key === 'favorites' ? key : 'library';
                }

                this.filters.folder = null;
                this.filters.smart = key;
                this.treeOpen = false;
                this.reload();

                if (key === 'trash') {
                    this.loadTrashedFolders();
                }
            },

            // ---------- Prullenbak ----------

            get inTrash() {
                return this.filters.smart === 'trash';
            },

            // Definitief verwijderen en samenvoegen vragen het recht purge_media.
            get canPurge() {
                return this.manage && !! this.config.canPurge;
            },

            async loadTrashedFolders() {
                try {
                    const result = await wire.loadTrashedFolders();

                    this.trashedFolders = (result && result.folders) || [];
                } catch (e) {
                    this.trashedFolders = [];
                }
            },

            trashedFolderLabel(folder) {
                return fill(this.L.trashedFolder, { count: folder.items || 0 });
            },

            // Zonder datum leegt de prullenbak zichzelf niet (de standaard).
            purgeLabel(item) {
                if (! item || ! item.trashed) {
                    return '';
                }

                if (item.purge_at && item.trashed_by_system && this.L.systemPurgeAt) {
                    return fill(this.L.systemPurgeAt, { date: this.date(item.purge_at) });
                }

                return item.purge_at ? fill(this.L.purgeAt, { date: this.date(item.purge_at) }) : (this.L.keptUntilPurged || '');
            },

            deleteBody(info) {
                const days = info.days || this.config.trashDays;

                return days ? fill(this.L.deleteItemBody, { days }) : this.L.deleteItemBodyKept;
            },

            purgeShort(item) {
                return item && item.purge_at ? this.date(item.purge_at) : (this.L.inTrash || '');
            },

            // Haalt items uit de lijst die er na een bewerking niet meer in horen.
            dropFromList(ids) {
                const gone = {};
                ids.forEach((id) => { gone[id] = true; });

                const before = this.items.length;

                this.items = this.items.filter((item) => ! gone[item.id]);

                if (this.total !== null) {
                    this.total = Math.max(0, this.total - (before - this.items.length));
                }

                this.clearSelection();
                this.focusIndex = Math.max(0, Math.min(this.items.length - 1, this.focusIndex));
            },

            async describeDelete(ids) {
                try {
                    return (await wire.describeDelete(ids)) || {};
                } catch (e) {
                    return {};
                }
            },

            async askDeleteSelected() {
                const ids = this.targetIds();

                if (! ids.length) {
                    return;
                }

                const info = await this.describeDelete(ids);

                this.openDialog({
                    type: 'confirm',
                    title: fill(this.L.deleteItemsTitle, { count: ids.length }),
                    body: this.deleteBody(info),
                    // Alleen als de index gevuld is valt hier iets over te zeggen.
                    note: info.inUse ? fill(this.L.deleteInUse, { count: info.inUse }) : '',
                    caveat: this.L.indexCaveat,
                    action: this.L.delete,
                    danger: true,
                    run: async () => {
                        const result = await wire.deleteItems(ids);

                        if (! result.ok) {
                            return;
                        }

                        this.dropFromList(ids);
                        this.applyTree(result);
                        notify(this.L.trashed);
                    },
                });
            },

            async restoreItems(ids) {
                ids = ids || this.targetIds();

                if (! ids.length) {
                    return;
                }

                await this.busyWhile(async () => {
                    const result = await wire.restoreItems(ids);

                    if (! result.ok) {
                        return;
                    }

                    if (this.inTrash) {
                        this.dropFromList(ids);
                    } else {
                        ids.forEach((id) => this.patchItem(id, { trashed: false, deleted_at: null, purge_at: null }));
                    }

                    this.applyTree(result);
                    notify(result.message || this.L.restored);
                });
            },

            async askPurge(ids) {
                ids = ids || this.targetIds();

                if (! ids.length || ! this.canPurge) {
                    return;
                }

                // Hoeveel hiervan toont de site nog, of kent de index nog.
                let info = {};

                try {
                    info = (await wire.describePurge(ids)) || {};
                } catch (e) {
                    info = {};
                }

                this.openDialog({
                    type: 'confirm',
                    title: ids.length === 1 ? this.L.purgeTitleOne : fill(this.L.purgeTitle, { count: ids.length }),
                    body: this.L.purgeBody,
                    note: info.inUse ? fill(this.L.purgeInUse, { count: info.inUse }) : '',
                    caveat: this.L.indexCaveat,
                    action: this.L.purge,
                    danger: true,
                    run: async () => {
                        const result = await wire.purgeItems(ids);

                        if (! result.ok) {
                            return;
                        }

                        this.dropFromList(ids);
                        this.applyTree(result);
                        notify(this.L.purged);
                    },
                });
            },

            async restoreFolder(id) {
                await this.busyWhile(async () => {
                    const result = await wire.restoreFolder(id);

                    if (! this.applyTree(result)) {
                        return;
                    }

                    notify(this.L.restored);
                    this.loadTrashedFolders();
                    this.reload();
                });
            },

            // ---------- Waar gebruikt ----------

            usageHeading(detail) {
                const count = detail.usage_count;

                if (count === null || count === undefined) {
                    return '';
                }

                if (count === 0) {
                    return this.L.usageNone;
                }

                return count === 1 ? this.L.usageTitleOne : fill(this.L.usageTitle, { count });
            },

            usageWhere(usage) {
                return [usage.field, usage.locale, usage.trashed ? this.L.usageTrashed : ''].filter(Boolean).join(' · ');
            },

            usageMore(detail) {
                return fill(this.L.usageMore, { count: (detail.usage_count || 0) - (detail.usages || []).length });
            },

            // ---------- Dubbele bestanden ----------

            // Samenvoegen kan alleen met het recht ervoor, met een gevulde index
            // (anders is niet te weten waar de bestanden gebruikt worden) en als
            // alles wat geselecteerd is dezelfde inhoud heeft.
            get canMerge() {
                if (! this.canPurge || ! this.tree.usageReady || this.inTrash || this.selectedCount < 2) {
                    return false;
                }

                let group = null;

                return this.selectedIds.every((id) => {
                    const item = this.items.find((entry) => entry.id === id);

                    if (! item || ! item.duplicate_group || (group !== null && group !== item.duplicate_group)) {
                        return false;
                    }

                    group = item.duplicate_group;

                    return true;
                });
            },

            askMerge() {
                const items = this.selectedIds.map((id) => this.items.find((entry) => entry.id === id)).filter(Boolean);

                if (items.length < 2) {
                    return;
                }

                // Het voorstel: het bestand dat het meest gebruikt wordt, anders het oudste.
                const keep = [...items].sort((a, b) => ((b.usage_count || 0) - (a.usage_count || 0)) || (a.id - b.id))[0];

                const alts = new Set(items.map((entry) => entry.alt || ''));

                this.openDialog({ type: 'merge', items, keep: keep.id, altDiffers: alts.size > 1 });
            },

            mergeMeta(entry) {
                const parts = [this.date(entry.created_at)];

                if (entry.usage_count !== null && entry.usage_count !== undefined) {
                    parts.push(entry.usage_count === 0 ? this.L.usageNone : (entry.usage_count === 1 ? this.L.usageTitleOne : fill(this.L.usageTitle, { count: entry.usage_count })));
                }

                return parts.filter(Boolean).join(' · ');
            },

            async confirmMerge() {
                const dialog = this.dialog;
                const ids = (dialog.items || []).map((entry) => entry.id);

                await this.busyWhile(async () => {
                    const result = await wire.mergeDuplicates(dialog.keep, ids);

                    if (! result.ok) {
                        return;
                    }

                    this.applyTree(result);
                    notify(result.message, (result.keptOut || []).length ? 'warning' : 'success');

                    // De aantallen en de groepen zijn veranderd: de lijst opnieuw.
                    this.reload();
                });

                this.closeDialog();
            },

            async undoMerge(batch) {
                if (! batch || ! this.canPurge) {
                    return;
                }

                await this.busyWhile(async () => {
                    const result = await wire.undoMerge(batch);

                    if (! result.ok) {
                        return;
                    }

                    this.applyTree(result);
                    notify(result.message, (result.failed || (result.missing || []).length) ? 'warning' : 'success');
                    this.reload();
                });
            },

            // ---------- Vervangen ----------

            // Vervangen is bewerken én uploaden.
            get canUploadHere() {
                return this.manage && !! this.can.upload && !! this.can.edit;
            },

            askReplace() {
                const input = ref('replaceFile');

                if (! input || ! this.detailOpen) {
                    return;
                }

                input.value = '';
                input.click();
            },

            replacePicked(event) {
                const file = (event.target.files || [])[0];
                const item = this.detail;

                if (! file || ! this.detailOpen || ! item.id) {
                    return;
                }

                if (this.tooLarge(file)) {
                    notify(this.tooLargeLabel(), 'danger');

                    return;
                }

                const id = item.id;

                this.openDialog({
                    type: 'confirm',
                    title: this.L.replaceTitle,
                    body: fill(this.L.replaceBody, { days: this.config.replacedDays || 30 }),
                    note: item.usage_count ? fill(this.L.replaceInUse, { count: item.usage_count }) : '',
                    lines: [file.name],
                    action: this.L.replace,
                    run: () => this.replaceWith(id, file),
                });
            },

            // Dezelfde uploadweg als elk ander bestand; pas daarna de wissel.
            replaceWith(id, file) {
                const key = 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);

                const posterOf = this.config.browserPosters ? videoPoster(file) : null;

                return new Promise((resolve) => {
                    wire.upload(
                        'uploads.' + key,
                        file,
                        async () => {
                            let result;

                            try {
                                result = await wire.replaceWithUpload(id, key, posterOf ? await uploadPoster(wire, key, posterOf) : null);
                            } catch (e) {
                                result = null;
                            }

                            if (! result || ! result.ok) {
                                notify((result && result.error) || this.L.uploadFailed, 'danger');
                            } else {
                                this.patchItem(id, result.item);
                                notify(this.L.replaced);

                                // Het paneel opnieuw: de formaten en de url zijn nieuw.
                                if (this.detailOpen && this.detail.id === id) {
                                    this.openDetail(id);
                                }
                            }

                            resolve();
                        },
                        () => {
                            notify(this.uploadError(key, file), 'danger');
                            resolve();
                        },
                    );
                });
            },

            isCurrent(folder) {
                return this.filters.folder === folder && (folder !== null || ! this.filters.smart);
            },

            treeKey(event) {
                const nodes = Array.from(ref('tree').querySelectorAll('[role="treeitem"]'));
                const index = nodes.indexOf(event.target);

                if (index < 0 || this.renaming !== null) {
                    return;
                }

                const id = Number(event.target.dataset.folder);
                const move = (to) => {
                    event.preventDefault();
                    nodes[Math.max(0, Math.min(nodes.length - 1, to))]?.focus();
                };

                if (event.key === 'ArrowDown') {
                    move(index + 1);
                } else if (event.key === 'ArrowUp') {
                    move(index - 1);
                } else if (event.key === 'Home') {
                    move(0);
                } else if (event.key === 'End') {
                    move(nodes.length - 1);
                } else if (event.key === 'ArrowRight' && id && ! this.expanded[id]) {
                    event.preventDefault();
                    this.toggleFolder(id);
                } else if (event.key === 'ArrowLeft' && id && this.expanded[id]) {
                    event.preventDefault();
                    this.toggleFolder(id);
                } else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    event.target.click();
                } else if (event.key === 'F2' && id && this.manage) {
                    event.preventDefault();
                    this.startRename(id, event.target.dataset.name || '');
                } else if ((event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) && id && this.manage) {
                    // Het menu van de map: de knop zelf staat buiten de tabvolgorde.
                    event.preventDefault();
                    this.folderMenu = id;
                    this.$nextTick(() => event.target.querySelector('[role="menuitem"]')?.focus());
                }
            },

            closeFolderMenu(id) {
                this.folderMenu = null;
                this.$nextTick(() => ref('tree')?.querySelector('[data-folder="' + id + '"]')?.focus());
            },

            startRename(id, name) {
                this.folderMenu = null;
                this.renaming = id;
                this.renameValue = name;
                this.$nextTick(() => {
                    const input = ref('tree').querySelector('[data-rename="' + id + '"]');
                    input?.focus();
                    input?.select();
                });
            },

            async commitRename() {
                const id = this.renaming;

                if (id === null) {
                    return;
                }

                this.renaming = null;

                const current = this.allFolders.find((row) => row.id === id);

                if (! current || current.name === this.renameValue.trim() || this.renameValue.trim() === '') {
                    return;
                }

                this.applyTree(await wire.renameFolder(id, this.renameValue));
            },

            applyTree(result) {
                if (result && result.ok && result.tree) {
                    this.tree = result.tree;
                    this.watchMoving();
                }

                return !! (result && result.ok);
            },

            askNewFolder(parentId) {
                this.folderMenu = null;
                this.openDialog({ type: 'folder', parentId: parentId || null, name: '' });
            },

            askMoveFolder(id) {
                this.folderMenu = null;
                this.openDialog({ type: 'move', kind: 'folder', folderId: id, target: undefined });
            },

            askDeleteFolder(id) {
                this.folderMenu = null;
                this.openDialog({ type: 'confirm', folderId: id, title: this.L.deleteFolderTitle, body: this.L.deleteFolderBody, action: this.L.delete, danger: true, run: async () => {
                    const result = await wire.deleteFolder(id);

                    if (this.applyTree(result)) {
                        notify(this.L.trashed);

                        // De map kan bestanden bevatten die nu in beeld staan (alle bestanden, een bovenliggende map).
                        if (this.filters.folder === id) {
                            this.openFolder(null);
                        } else {
                            this.reload();
                        }
                    }
                } });
            },

            // ---------- Selectie ----------

            isSelected(id) {
                return !! this.selected[id];
            },

            get selectedIds() {
                return Object.keys(this.selected).map(Number);
            },

            setSelection(ids, note) {
                const map = {};
                ids.forEach((id) => { map[id] = true; });
                this.selected = map;
                this.selectedCount = ids.length;
                this.selectionNote = note || '';
                this.live = ids.length ? fill(this.L.selectedCount, { count: ids.length }) : '';

                if (ids.length === 1 && ! this.pick) {
                    this.openDetail(ids[0]);
                } else {
                    detailSeq++;
                    this.detailOpen = false;
                }
            },

            clearSelection() {
                this.anchor = null;
                this.setSelection([]);
            },

            toggleIndex(index) {
                const item = this.items[index];

                if (! item) {
                    return;
                }

                const ids = this.selectedIds.filter((id) => id !== item.id);

                if (ids.length === this.selectedCount) {
                    ids.push(item.id);
                }

                this.anchor = index;
                this.setSelection(ids);
            },

            selectRange(from, to, keep) {
                const [start, end] = from <= to ? [from, to] : [to, from];
                const ids = keep ? this.selectedIds : [];

                this.items.slice(start, end + 1).forEach((item) => {
                    if (! ids.includes(item.id)) {
                        ids.push(item.id);
                    }
                });

                this.setSelection(ids);
            },

            clickItem(index, event) {
                const item = this.items[index];

                if (! item) {
                    return;
                }

                this.focusIndex = index;

                if (this.pick) {
                    this.pickToggle(item.id);

                    return;
                }

                if (event.shiftKey && this.anchor !== null) {
                    this.selectRange(this.anchor, index, event.metaKey || event.ctrlKey);
                } else if (event.metaKey || event.ctrlKey) {
                    this.toggleIndex(index);
                } else {
                    this.anchor = index;
                    this.setSelection([item.id]);
                }
            },

            tick(index) {
                this.focusIndex = index;

                if (this.pick) {
                    this.pickToggle(this.items[index]?.id);
                } else {
                    this.toggleIndex(index);
                }

                this.focusTile(index, false);
            },

            // ---------- Kies-modus ----------

            isChosen(id) {
                return !! this.chosen[id];
            },

            get canUpload() {
                return this.manage ? !! this.can.upload : !! (this.pick && this.pick.upload);
            },

            // Hoeveel er nog bij kan: een meervoudig veld vult aan wat er al staat.
            get pickRoom() {
                if (! this.pick || ! this.pick.multiple) {
                    return 1;
                }

                return this.pick.max ? Math.max(0, this.pick.max - Object.keys(this.chosen).length) : Infinity;
            },

            get pickCountLabel() {
                if (! this.pick) {
                    return '';
                }

                return this.pick.multiple && this.pick.max
                    ? fill(this.PL.countMax, { count: this.selectedCount + Object.keys(this.chosen).length, max: this.pick.max })
                    : fill(this.PL.count, { count: this.selectedCount });
            },

            // Enkel veld: de klik vervangt de keuze. Meervoudig: aan- of uitvinken.
            pickToggle(id, onlyAdd) {
                if (! this.pick || ! id) {
                    return false;
                }

                if (! this.pick.multiple) {
                    this.pickOrder = [id];
                    this.setSelection([id]);

                    return true;
                }

                if (this.chosen[id]) {
                    return false;
                }

                if (this.selected[id]) {
                    if (! onlyAdd) {
                        this.pickOrder = this.pickOrder.filter((entry) => entry !== id);
                        this.setSelection(this.pickOrder.slice());
                    }

                    return true;
                }

                if (this.selectedCount >= this.pickRoom) {
                    notify(fill(this.PL.maxReached, { max: this.pick.max }), 'warning');

                    return false;
                }

                this.pickOrder = [...this.pickOrder, id];
                this.setSelection(this.pickOrder.slice());

                return true;
            },

            dblItem(index) {
                const item = this.items[index];

                if (this.pick && ! this.pick.multiple && item) {
                    this.pickToggle(item.id);
                    this.confirmPick();
                }
            },

            setPickTab(tab) {
                if (! this.pick || this.pickTab === tab) {
                    return;
                }

                if (tab === 'recent' || tab === 'favorites') {
                    this.openSmart(tab);
                } else if (tab === 'library') {
                    const wasList = this.pickTab === 'upload' || this.pickTab === 'source';

                    this.pickTab = 'library';

                    // Terug van het uploadtabblad staat de lijst er nog.
                    if (! wasList || this.filters.smart) {
                        this.openFolder(this.pickFolder);
                    }
                } else {
                    this.pickTab = 'upload';
                }
            },

            cancelPick() {
                root.dispatchEvent(new CustomEvent('dashed-media-pick-cancel', { bubbles: true }));
            },

            accepts(file) {
                const accept = (this.pick && this.pick.accept) || [];

                return ! accept.length || accept.some((type) => type.endsWith('/*')
                    ? (file.type || '').toLowerCase().startsWith(type.slice(0, -1))
                    : (file.type || '').toLowerCase() === type);
            },

            selectLoaded() {
                this.setSelection(this.items.map((item) => item.id));
            },

            async selectAllInFilter() {
                const result = await wire.selectAll(this.cleanFilters());

                this.setSelection(result.ids || [], fill(result.capped ? this.L.selectedCapped : this.L.selectedAll, { count: result.count }));
                this.live = this.selectionNote;
            },

            columns() {
                if (this.view === 'list') {
                    return 1;
                }

                const tiles = ref('grid') ? ref('grid').querySelectorAll('[role="option"]') : [];

                if (! tiles.length) {
                    return 1;
                }

                const top = tiles[0].offsetTop;
                let count = 0;

                while (count < tiles.length && tiles[count].offsetTop === top) {
                    count++;
                }

                return Math.max(1, count);
            },

            focusTile(index, scroll = true) {
                this.focusIndex = index;
                this.$nextTick(() => {
                    const tile = ref('grid')?.querySelector('[data-index="' + index + '"]');
                    tile?.focus({ preventScroll: ! scroll });
                });
            },

            gridKey(event) {
                if (isTyping(event) || ! this.items.length) {
                    return;
                }

                const last = this.items.length - 1;
                const key = event.key;
                let next = this.focusIndex;

                if (this.pick && (key === ' ' || key === 'Enter')) {
                    event.preventDefault();

                    const id = this.items[this.focusIndex].id;

                    // Enter bevestigt: bij een enkel veld het item onder de
                    // cursor, bij een meervoudig veld wat er aangevinkt staat.
                    if (key === ' ') {
                        this.pickToggle(id);
                    } else if (! this.pick.multiple) {
                        this.pickToggle(id);
                        this.confirmPick();
                    } else if (this.selectedCount) {
                        this.confirmPick();
                    } else {
                        this.pickToggle(id);
                    }

                    return;
                }

                if (key === ' ') {
                    event.preventDefault();
                    this.toggleIndex(this.focusIndex);

                    return;
                }

                if (key === 'Enter') {
                    event.preventDefault();
                    this.anchor = this.focusIndex;
                    this.setSelection([this.items[this.focusIndex].id]);

                    return;
                }

                // De ster op de tegel staat buiten de tabvolgorde: f is zijn toets.
                if (key.toLowerCase() === 'f' && ! event.metaKey && ! event.ctrlKey && ! event.altKey) {
                    event.preventDefault();
                    this.toggleFavorite(this.items[this.focusIndex].id);

                    return;
                }

                if (key === 'Delete') {
                    const ids = this.selectedCount ? this.selectedIds : [this.items[this.focusIndex].id];

                    // Verwijderen gaat per stuk; bij meer geselecteerde items doet de toets niets.
                    if (this.manage && ids.length === 1) {
                        event.preventDefault();
                        this.askDelete(ids[0]);
                    }

                    return;
                }

                if (key.toLowerCase() === 'a' && (event.metaKey || event.ctrlKey)) {
                    event.preventDefault();

                    if (! this.pick) {
                        this.selectLoaded();
                    }

                    return;
                }

                if (key === 'ArrowRight') {
                    next++;
                } else if (key === 'ArrowLeft') {
                    next--;
                } else if (key === 'ArrowDown') {
                    next += this.columns();
                } else if (key === 'ArrowUp') {
                    next -= this.columns();
                } else if (key === 'Home') {
                    next = 0;
                } else if (key === 'End') {
                    next = last;
                } else {
                    return;
                }

                event.preventDefault();
                next = Math.max(0, Math.min(last, next));

                if (event.shiftKey && ! this.pick) {
                    if (this.anchor === null) {
                        this.anchor = this.focusIndex;
                    }

                    this.selectRange(this.anchor, next, false);
                }

                this.focusTile(next);
            },

            // ---------- Detail ----------

            async openDetail(id) {
                const token = ++detailSeq;
                const known = this.items.find((item) => item.id === id);

                // Meteen tonen wat de lijst al weet; de rest komt erachteraan.
                if (known) {
                    this.detail = Object.assign({ title: null, caption: null, source: null, uploader: null, breadcrumb: [], conversions: [], actions: [] }, known);
                    this.syncForm();
                    this.syncFocal();
                }

                this.detailOpen = !! known;
                this.detailLoading = true;
                this.tagInput = '';
                this.tagSuggestions = [];

                let result;

                try {
                    result = await wire.loadItem(id);
                } catch (e) {
                    result = null;
                }

                if (token !== detailSeq) {
                    return;
                }

                this.detailLoading = false;

                if (! result || ! result.ok) {
                    this.detailOpen = false;

                    return;
                }

                this.detail = result.item;
                this.detailOpen = true;
                this.syncForm();
                this.syncFocal();
            },

            syncForm() {
                this.form = { title: this.textOf('title', this.textLocale), alt: this.textOf('alt', this.textLocale), caption: this.textOf('caption', this.textLocale) };
                this.saved = {};
            },

            // De tekst van een veld in een taal. Zonder talen (een oudere
            // server) de kale waarde van het item.
            textOf(field, locale) {
                const texts = this.detail.texts;

                if (! locale || ! texts || ! texts[field]) {
                    return this.detail[field] || '';
                }

                return texts[field][locale] || '';
            },

            hasAlt(locale) {
                return this.textOf('alt', locale) !== '';
            },

            setTextLocale(locale) {
                if (this.textLocale === locale) {
                    return;
                }

                // Een veld dat nog focus had is op dit punt al onderweg: blur
                // gaat voor de klik en saveField legt zijn taal zelf vast.
                this.textLocale = locale;
                this.syncForm();
            },

            closeDetail() {
                this.clearSelection();
            },

            patchItem(id, values) {
                const item = this.items.find((entry) => entry.id === id);

                if (item) {
                    Object.assign(item, values);
                }

                if (this.detailOpen && this.detail.id === id) {
                    Object.assign(this.detail, values);
                }
            },

            async saveField(field) {
                if (! this.detailOpen || ! this.manage) {
                    return;
                }

                const id = this.detail.id;
                const value = this.form[field];
                const locale = this.textLocale;

                if (this.textOf(field, locale) === value) {
                    return;
                }

                const result = await wire.saveText(id, field, value, locale);

                if (! result.ok) {
                    if (this.detailOpen && this.detail.id === id && this.textLocale === locale) {
                        this.form[field] = this.textOf(field, locale);
                    }

                    return;
                }

                this.patchItem(id, { name: result.name, alt: result.alt, alt_missing: result.alt_missing || [] });

                if (this.detailOpen && this.detail.id === id) {
                    this.detail.title = result.title;
                    this.detail.caption = result.caption;
                    // De stippen per taal lezen hieruit. Een nieuw object, met de
                    // opgeslagen waarde er hoe dan ook in: ook als het antwoord de
                    // vertalingen niet (of als lege lijst) meegaf springt de stip om.
                    const texts = Object.assign({ title: {}, alt: {}, caption: {} }, JSON.parse(JSON.stringify(result.texts || this.detail.texts || {})));
                    ['title', 'alt', 'caption'].forEach((name) => {
                        texts[name] = (texts[name] && ! Array.isArray(texts[name])) ? Object.assign({}, texts[name]) : {};
                    });

                    if (locale) {
                        const stored = String(value || '').trim();

                        if (stored) {
                            texts[field][locale] = stored;
                        } else {
                            delete texts[field][locale];
                        }
                    }

                    this.detail.texts = texts;
                    this.saved[field] = true;
                    this.live = this.L.saved;
                    setTimeout(() => { delete this.saved[field]; }, 2500);
                }
            },

            async retryAltText() {
                if (! this.detailOpen || ! this.manage) {
                    return;
                }

                const id = this.detail.id;
                let result = null;

                try {
                    result = await wire.retryAltText(id);
                } catch (e) {
                    result = null;
                }

                if (! result || ! result.ok) {
                    return;
                }

                if (this.detailOpen && this.detail.id === id) {
                    this.detail.ai_alt_failed = false;
                }

                if (result.message) {
                    notify(result.message, 'success');
                }
            },

            async saveTags(names) {
                const id = this.detail.id;
                const result = await wire.setTags([id], names, 'replace');

                if (result.ok && result.tags) {
                    this.patchItem(id, { tags: result.tags[id] || [] });
                    this.saved.tags = true;
                    setTimeout(() => { delete this.saved.tags; }, 2500);
                }
            },

            addTag(name) {
                name = String(name || '').trim();
                this.tagInput = '';
                this.tagSuggestions = [];

                if (name === '' || ! this.detailOpen) {
                    return;
                }

                this.saveTags([...(this.detail.tags || []), name]);
            },

            removeTag(name) {
                this.saveTags((this.detail.tags || []).filter((tag) => tag !== name));
            },

            suggestTags() {
                clearTimeout(tagTimer);
                tagTimer = setTimeout(async () => {
                    const search = this.tagInput;
                    const tags = await wire.allTags(search);

                    if (search === this.tagInput && this.detailOpen) {
                        this.tagSuggestions = tags.map((tag) => tag.name).filter((name) => ! (this.detail.tags || []).includes(name)).slice(0, 8);
                    }
                }, 250);
            },

            async loadTagOptions() {
                const search = this.tagSearch;
                const tags = await wire.allTags(search);

                if (search !== this.tagSearch) {
                    return;
                }

                this.tagOptions = tags;
                tags.forEach((tag) => { this.tagNames[tag.id] = tag.name; });
            },

            async toggleFavorite(id) {
                const result = await wire.toggleFavorite(id);

                if (result.ok) {
                    this.patchItem(id, { is_favorite: result.is_favorite });
                }
            },

            async copyUrl() {
                try {
                    await navigator.clipboard.writeText(new URL(this.detail.url, window.location.href).href);
                    notify(this.L.copied);
                } catch (e) {
                    notify(this.L.copyFailed, 'danger');
                }
            },

            async askDelete(id) {
                // Eerst vragen waar het bestand gebruikt wordt: dat hoort in de bevestiging.
                const info = await this.describeDelete([id]);
                const places = info.places || 0;

                this.openDialog({
                    type: 'confirm',
                    title: this.L.deleteItemTitle,
                    body: this.deleteBody(info),
                    note: places ? fill(this.L.deleteInUseOne, { count: places }) : '',
                    lines: (info.usages || []).map((usage) => [usage.label, usage.title].filter(Boolean).join(': ')),
                    action: this.L.delete,
                    danger: true,
                    run: async () => {
                        const result = await wire.deleteItem(id);

                        if (! result.ok) {
                            return;
                        }

                        this.dropFromList([id]);
                        this.applyTree(result);
                        notify(this.L.trashed);
                    },
                });
            },

            // ---------- Bulk en dialogen ----------

            targetIds() {
                return this.selectedIds;
            },

            askMoveItems(ids) {
                this.openDialog({ type: 'move', kind: 'items', ids: ids || this.targetIds(), target: undefined });
            },

            moveDisabled(folderId) {
                if (! this.dialogOpen || this.dialog.kind !== 'folder') {
                    return false;
                }

                // Een map kan niet in zichzelf of in een eigen submap.
                return folderId !== null && this.folderTrail(folderId).some((step) => step.id === this.dialog.folderId);
            },

            async confirmMove() {
                const dialog = this.dialog;

                if (! this.dialogOpen || dialog.target === undefined) {
                    return;
                }

                let ask = null;

                await this.busyWhile(async () => {
                    ask = dialog.kind === 'folder'
                        ? await this.moveFolderTo(dialog.folderId, dialog.target, false, true)
                        : await this.moveItemsTo(dialog.ids, dialog.target, false, true);
                });

                this.closeDialog();

                if (ask) {
                    this.$nextTick(ask);
                }
            },

            /**
             * Gaat een bestand een privé-map in of uit, dan vraagt de server
             * eerst een bevestiging. Met `defer` komt die vraag terug als
             * functie (de aanroeper sluit eerst zijn eigen venster); anders
             * opent hij meteen.
             */
            async moveItemsTo(ids, folderId, confirmed, defer) {
                const result = await wire.moveItems(ids, folderId, !! confirmed);

                if (result && result.confirm) {
                    const ask = () => this.askVisibility(result.confirm, () => this.moveItemsTo(ids, folderId, true));

                    return defer ? ask : ask();
                }

                if (this.applyTree(result)) {
                    notify(this.L.moved);
                    this.reload();
                }

                return null;
            },

            async moveFolderTo(id, parentId, confirmed, defer) {
                const result = await wire.moveFolder(id, parentId, !! confirmed);

                if (result && result.confirm) {
                    const ask = () => this.askVisibility(result.confirm, () => this.moveFolderTo(id, parentId, true));

                    return defer ? ask : ask();
                }

                if (this.applyTree(result)) {
                    notify(this.L.moved);

                    if (parentId) {
                        this.expanded[parentId] = true;
                        stored.set('expanded', this.expanded);
                    }
                }

                return null;
            },

            async confirmFolder() {
                const dialog = this.dialog;

                await this.busyWhile(async () => {
                    const result = await wire.createFolder(dialog.name, dialog.parentId);

                    if (this.applyTree(result)) {
                        notify(this.L.folderCreated);
                        // De nieuwe map openen (dat klapt ook zijn ouders uit) en de focus erop.
                        this.closeDialog('[data-folder="' + result.folder.id + '"]');
                        this.openFolder(result.folder.id);
                    }
                });
            },

            askTags() {
                this.openDialog({ type: 'tags', mode: 'add', names: '' });
            },

            async confirmTags() {
                const dialog = this.dialog;
                const names = dialog.names.split(',').map((name) => name.trim()).filter(Boolean);

                if (! names.length) {
                    return;
                }

                await this.busyWhile(async () => {
                    const result = await wire.setTags(this.targetIds(), names, dialog.mode);

                    if (! result.ok) {
                        return;
                    }

                    if (result.tags) {
                        Object.keys(result.tags).forEach((id) => this.patchItem(Number(id), { tags: result.tags[id] }));
                    }

                    notify(this.L.tagsSaved);
                    this.closeDialog();
                });
            },

            async bulkFavorite() {
                const ids = this.targetIds();
                const result = await wire.setFavorite(ids, true);

                if (result.ok) {
                    ids.forEach((id) => this.patchItem(id, { is_favorite: true }));
                    notify(this.L.favoritesSaved);
                }
            },

            downloadZip() {
                const ids = this.targetIds();

                if (ids.length > (this.config.zipMaxFiles || 200)) {
                    notify(fill(this.L.zipTooMany, { max: this.config.zipMaxFiles || 200 }), 'danger');

                    return;
                }

                wire.downloadZip(ids).then((result) => {
                    if (! result || ! result.ok || ! result.url) {
                        return;
                    }

                    notify(fill(this.L.zipReady, { count: result.count }));
                    // Content-Disposition: attachment, dus de pagina blijft staan.
                    window.location.assign(result.url);
                });
            },

            action(slot, key) {
                return ((this.config.actions || {})[slot] || []).find((action) => action.key === key);
            },

            async runAction(slot, key) {
                let action = this.action(slot, key);

                if (! action) {
                    return;
                }

                const ids = slot === 'item' ? (this.detailOpen ? [this.detail.id] : []) : (slot === 'bulk' ? this.targetIds() : []);

                // De bevestiging hangt van de selectie af (hoeveel het er
                // echt worden): die tekst komt van de server.
                if (action.confirmAsk) {
                    let asked = null;

                    try {
                        asked = await wire.runMediaAction(slot, key, ids, { _ask: true });
                    } catch (e) {
                        asked = null;
                    }

                    if (! asked || ! asked.ok) {
                        return;
                    }

                    action = Object.assign({}, action, { confirm: asked.confirm || null });
                }

                if (action.confirm || (action.fields || []).length) {
                    const data = {};
                    (action.fields || []).forEach((field) => { data[field.name] = ''; });
                    this.openDialog({ type: 'action', slot, action, ids, data });

                    return;
                }

                this.execAction(slot, key, ids, {});
            },

            async execAction(slot, key, ids, data) {
                const result = await wire.runMediaAction(slot, key, ids, data);

                if (result.ok && slot === 'item' && this.detailOpen && ids[0] === this.detail.id) {
                    this.openDetail(this.detail.id);
                }

                return result;
            },

            async confirmAction() {
                const dialog = this.dialog;

                await this.busyWhile(async () => {
                    const result = await this.execAction(dialog.slot, dialog.action.key, dialog.ids, Object.assign({}, dialog.data));

                    if (result.ok) {
                        this.closeDialog();
                    }
                });
            },

            async confirmGeneric() {
                const dialog = this.dialog;

                await this.busyWhile(() => dialog.run());
                this.closeDialog();
            },

            async busyWhile(callback) {
                if (this.busy) {
                    return;
                }

                this.busy = true;

                try {
                    await callback();
                } finally {
                    this.busy = false;
                }
            },

            openDialog(dialog) {
                lastFocus = document.activeElement;
                // Een menuknop is weg tegen de tijd dat het venster sluit: dan naar de rij van die map.
                dialog.returnTo = dialog.folderId || dialog.parentId ? '[data-folder="' + (dialog.folderId || dialog.parentId) + '"]' : null;
                this.dialog = dialog;
                this.dialogOpen = true;
                this.$nextTick(() => {
                    const el = ref('dialog');
                    (el?.querySelector('[data-autofocus]') || el?.querySelector('input, select, textarea, button'))?.focus();
                });
            },

            closeDialog(focusSelector) {
                if (! this.dialogOpen) {
                    return;
                }

                const opener = lastFocus;
                const fallback = focusSelector || this.dialog.returnTo;

                this.dialogOpen = false;
                this.busy = false;
                lastFocus = null;

                // Terug naar wat het venster opende; bestaat dat niet meer,
                // dan naar de mapregel of het raster.
                this.$nextTick(() => {
                    const target = (! focusSelector && opener && opener.isConnected && root.contains(opener) && opener.offsetParent !== null ? opener : null)
                        || (fallback ? root.querySelector(fallback) : null)
                        || ref('grid')?.querySelector('[tabindex="0"]')
                        || root.querySelector('[role="treeitem"]');

                    target?.focus({ preventScroll: true });
                });
            },

            trapFocus(event) {
                const nodes = Array.from(ref('dialog')?.querySelectorAll('button:not([disabled]), input, select, textarea') || []).filter((node) => node.offsetParent !== null);

                if (! nodes.length) {
                    return;
                }

                const first = nodes[0];
                const last = nodes[nodes.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (! event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            },

            // ---------- Fase 7: focuspunt ----------

            syncFocal() {
                const focal = this.detail && this.detail.focal;

                this.focal = focal && Number.isFinite(focal.x) && Number.isFinite(focal.y) ? { x: focal.x, y: focal.y } : { x: 50, y: 50 };
                this.focalDirty = false;
            },

            focalText() {
                return fill(this.L.focalAt, { x: this.focal.x, y: this.focal.y });
            },

            focalSet(x, y) {
                const clamp = (value) => Math.max(0, Math.min(100, Math.round(value)));
                const next = { x: clamp(x), y: clamp(y) };

                if (next.x === this.focal.x && next.y === this.focal.y) {
                    return;
                }

                this.focal = next;
                this.focalDirty = true;
            },

            focalFromPointer(event, stage) {
                const rect = stage.getBoundingClientRect();

                if (! rect.width || ! rect.height) {
                    return;
                }

                this.focalSet((event.clientX - rect.left) / rect.width * 100, (event.clientY - rect.top) / rect.height * 100);
            },

            focalDown(event) {
                if (this.busy || (event.button !== undefined && event.button > 0)) {
                    return;
                }

                const stage = event.currentTarget;

                event.preventDefault();
                stage.focus({ preventScroll: true });
                this.focalFromPointer(event, stage);

                const move = (moveEvent) => this.focalFromPointer(moveEvent, stage);
                const up = () => {
                    stage.removeEventListener('pointermove', move);
                    stage.removeEventListener('pointerup', up);
                    stage.removeEventListener('pointercancel', up);
                };

                try {
                    stage.setPointerCapture(event.pointerId);
                } catch (e) {
                    // Zonder capture werkt slepen binnen het vlak nog steeds.
                }

                stage.addEventListener('pointermove', move);
                stage.addEventListener('pointerup', up);
                stage.addEventListener('pointercancel', up);
            },

            focalKey(event) {
                const steps = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
                const step = steps[event.key];

                if (step) {
                    const size = event.shiftKey ? 10 : 1;

                    event.preventDefault();
                    event.stopPropagation();
                    this.focalSet(this.focal.x + step[0] * size, this.focal.y + step[1] * size);

                    return;
                }

                if (event.key === 'Enter' && this.focalDirty) {
                    event.preventDefault();
                    this.focalSave();
                }
            },

            focalCenter() {
                this.focalSet(50, 50);

                // Stond er een focuspunt, dan is "Midden" meteen het antwoord.
                if (this.detail.focal) {
                    this.focalSave();
                } else {
                    this.focalDirty = false;
                }
            },

            async focalSave() {
                const id = this.detail.id;
                const centre = this.focal.x === 50 && this.focal.y === 50;

                await this.busyWhile(async () => {
                    let result;

                    try {
                        result = await wire.setFocalPoint(id, centre ? null : this.focal.x, centre ? null : this.focal.y);
                    } catch (e) {
                        result = null;
                    }

                    if (! result || ! result.ok) {
                        return;
                    }

                    this.applyDetail(id, result.item);
                    notify(this.L.focalSaved);
                    this.loadRunStatus();
                });
            },

            // Het paneel en de tegel na een wijziging die het bestand of zijn formaten raakt.
            applyDetail(id, item) {
                const tile = this.items.find((entry) => entry.id === id);

                if (tile) {
                    ['thumb', 'thumb_small', 'animated', 'url', 'width', 'height', 'size', 'mime', 'file_name', 'name'].forEach((key) => {
                        if (item[key] !== undefined) {
                            tile[key] = item[key];
                        }
                    });
                }

                if (this.detailOpen && this.detail.id === id) {
                    this.detail = item;
                    this.syncForm();
                    this.syncFocal();
                }
            },

            // ---------- Fase 7: bijsnijden, draaien, spiegelen ----------

            askEdit() {
                const item = this.detail;

                if (! this.detailOpen || ! item.can_edit || ! item.edit_source) {
                    return;
                }

                const edits = item.edits || {};
                const flip = typeof edits.flip === 'string' ? edits.flip : '';
                const crop = edits.crop && typeof edits.crop === 'object' ? edits.crop : null;

                editImage = null;

                this.openDialog({
                    type: 'edit',
                    id: item.id,
                    usage: item.usage_count || 0,
                    hasEdits: !! item.edits,
                    ready: false,
                    failed: false,
                    rotate: [0, 90, 180, 270].includes(edits.rotate) ? edits.rotate : 0,
                    flipH: flip.includes('h'),
                    flipV: flip.includes('v'),
                    ratio: '',
                    crop: crop ? { x: Number(crop.x) || 0, y: Number(crop.y) || 0, w: Number(crop.w) || 100, h: Number(crop.h) || 100 } : { x: 0, y: 0, w: 100, h: 100 },
                    width: 0,
                    height: 0,
                });

                const image = new Image();
                const dialog = this.dialog;

                image.onload = () => {
                    if (this.dialog !== dialog || ! this.dialogOpen) {
                        return;
                    }

                    editImage = image;
                    this.dialog.ready = true;
                    this.$nextTick(() => this.editDraw());
                };

                image.onerror = () => {
                    if (this.dialog === dialog) {
                        this.dialog.failed = true;
                    }
                };

                image.src = item.edit_source;
            },

            // Tekent de bron zoals het resultaat eruit komt te zien: eerst gedraaid, dan gespiegeld.
            editDraw() {
                const canvas = ref('editCanvas');
                const dialog = this.dialog;

                if (! canvas || ! editImage || dialog.type !== 'edit') {
                    return;
                }

                const turned = dialog.rotate === 90 || dialog.rotate === 270;
                const width = turned ? editImage.naturalHeight : editImage.naturalWidth;
                const height = turned ? editImage.naturalWidth : editImage.naturalHeight;
                const scale = Math.min(1, 700 / width, (window.innerHeight * 0.45) / height);

                dialog.width = width;
                dialog.height = height;
                canvas.width = Math.max(1, Math.round(width * scale));
                canvas.height = Math.max(1, Math.round(height * scale));

                const context = canvas.getContext('2d');

                context.save();
                context.translate(canvas.width / 2, canvas.height / 2);
                context.scale(dialog.flipH ? -1 : 1, dialog.flipV ? -1 : 1);
                context.rotate(dialog.rotate * Math.PI / 180);
                context.drawImage(editImage, -editImage.naturalWidth * scale / 2, -editImage.naturalHeight * scale / 2, editImage.naturalWidth * scale, editImage.naturalHeight * scale);
                context.restore();
            },

            editCropStyle() {
                const crop = this.dialog.crop || { x: 0, y: 0, w: 100, h: 100 };

                return 'left:' + crop.x + '%;top:' + crop.y + '%;width:' + crop.w + '%;height:' + crop.h + '%';
            },

            // De verhouding van het kader in procenten: de afbeelding zelf is zelden vierkant.
            editAspect() {
                const parts = String(this.dialog.ratio || '').split(':').map(Number);

                if (parts.length !== 2 || ! parts[0] || ! parts[1] || ! this.dialog.width || ! this.dialog.height) {
                    return null;
                }

                return (parts[0] / parts[1]) * (this.dialog.height / this.dialog.width);
            },

            editClamp(crop) {
                const min = 2;

                crop.w = Math.max(min, Math.min(100, crop.w));
                crop.h = Math.max(min, Math.min(100, crop.h));
                crop.x = Math.max(0, Math.min(100 - crop.w, crop.x));
                crop.y = Math.max(0, Math.min(100 - crop.h, crop.y));

                const round = (value) => Math.round(value * 100) / 100;

                this.dialog.crop = { x: round(crop.x), y: round(crop.y), w: round(crop.w), h: round(crop.h) };
            },

            editRatio() {
                const aspect = this.editAspect();

                if (! aspect) {
                    return;
                }

                // Het grootste kader met deze verhouding dat om het midden van het huidige kader past.
                const crop = Object.assign({}, this.dialog.crop);
                const centreX = crop.x + crop.w / 2;
                const centreY = crop.y + crop.h / 2;
                let w = crop.w;
                let h = w / aspect;

                if (h > 100) {
                    h = 100;
                    w = h * aspect;
                }

                if (w > 100) {
                    w = 100;
                    h = w / aspect;
                }

                this.editClamp({ x: centreX - w / 2, y: centreY - h / 2, w, h });
            },

            editResize(crop, dw, dh, anchorRight, anchorBottom) {
                const aspect = this.editAspect();
                const right = crop.x + crop.w;
                const bottom = crop.y + crop.h;
                let w = Math.max(2, crop.w + dw);
                let h = Math.max(2, crop.h + dh);

                // Niet verder dan de rand aan de kant die beweegt.
                w = Math.min(w, anchorRight ? right : 100 - crop.x);
                h = Math.min(h, anchorBottom ? bottom : 100 - crop.y);

                if (aspect) {
                    h = w / aspect;

                    const room = anchorBottom ? bottom : 100 - crop.y;

                    if (h > room) {
                        h = room;
                        w = h * aspect;
                    }
                }

                this.editClamp({ x: anchorRight ? right - w : crop.x, y: anchorBottom ? bottom - h : crop.y, w, h });
            },

            editDown(event, mode) {
                const canvas = ref('editCanvas');

                if (! canvas || ! this.dialog.ready || (event.button !== undefined && event.button > 0)) {
                    return;
                }

                event.preventDefault();
                ref('editStage')?.focus({ preventScroll: true });

                const rect = canvas.getBoundingClientRect();
                const start = Object.assign({}, this.dialog.crop);
                const startX = event.clientX;
                const startY = event.clientY;
                const target = event.currentTarget;

                const move = (moveEvent) => {
                    const dx = (moveEvent.clientX - startX) / rect.width * 100;
                    const dy = (moveEvent.clientY - startY) / rect.height * 100;

                    if (mode === 'move') {
                        this.editClamp({ x: start.x + dx, y: start.y + dy, w: start.w, h: start.h });

                        return;
                    }

                    const west = mode.includes('w');
                    const north = mode.includes('n');

                    this.editResize(start, west ? -dx : dx, north ? -dy : dy, west, north);
                };

                const up = () => {
                    target.removeEventListener('pointermove', move);
                    target.removeEventListener('pointerup', up);
                    target.removeEventListener('pointercancel', up);
                };

                try {
                    target.setPointerCapture(event.pointerId);
                } catch (e) {
                    // Zonder capture stopt het slepen aan de rand van het kader.
                }

                target.addEventListener('pointermove', move);
                target.addEventListener('pointerup', up);
                target.addEventListener('pointercancel', up);
            },

            editKey(event) {
                const steps = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
                const step = steps[event.key];

                if (! step || ! this.dialog.ready) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                const crop = Object.assign({}, this.dialog.crop);

                if (event.shiftKey) {
                    this.editResize(crop, step[0], step[1], false, false);
                } else {
                    this.editClamp({ x: crop.x + step[0], y: crop.y + step[1], w: crop.w, h: crop.h });
                }
            },

            // De afbeelding zoals hij getoond wordt draait met de klok mee; na
            // één spiegeling is dat voor de bron de andere kant op.
            editRotate(degrees) {
                const mirrored = this.dialog.flipH !== this.dialog.flipV;

                this.dialog.rotate = (this.dialog.rotate + (mirrored ? -degrees : degrees) + 360) % 360;
                this.dialog.crop = { x: 0, y: 0, w: 100, h: 100 };
                this.editDraw();
                this.editRatio();
            },

            editFlip(axis) {
                if (axis === 'h') {
                    this.dialog.flipH = ! this.dialog.flipH;
                    this.dialog.crop.x = Math.round((100 - this.dialog.crop.x - this.dialog.crop.w) * 100) / 100;
                } else {
                    this.dialog.flipV = ! this.dialog.flipV;
                    this.dialog.crop.y = Math.round((100 - this.dialog.crop.y - this.dialog.crop.h) * 100) / 100;
                }

                this.editDraw();
            },

            editResetCrop() {
                this.dialog.ratio = '';
                this.dialog.crop = { x: 0, y: 0, w: 100, h: 100 };
            },

            editSummary() {
                const dialog = this.dialog;

                if (dialog.type !== 'edit' || ! dialog.width) {
                    return '';
                }

                return fill(this.L.editResult, {
                    width: Math.max(1, Math.round(dialog.width * dialog.crop.w / 100)),
                    height: Math.max(1, Math.round(dialog.height * dialog.crop.h / 100)),
                });
            },

            editNote() {
                const usage = this.dialog.usage || 0;

                return this.L.editNote + (usage > 0 ? ' ' + fill(usage === 1 ? this.L.editInUseOne : this.L.editInUse, { count: usage }) : '');
            },

            async confirmEdit() {
                const dialog = this.dialog;
                const crop = dialog.crop;
                const whole = crop.x <= 0 && crop.y <= 0 && crop.w >= 100 && crop.h >= 100;
                const recipe = {
                    rotate: dialog.rotate,
                    flip: ((dialog.flipH ? 'h' : '') + (dialog.flipV ? 'v' : '')) || null,
                    crop: whole ? null : { x: crop.x, y: crop.y, w: crop.w, h: crop.h },
                };

                await this.busyWhile(async () => {
                    let result;

                    try {
                        result = await wire.applyEdits(dialog.id, recipe);
                    } catch (e) {
                        result = null;
                    }

                    if (! result || ! result.ok) {
                        return;
                    }

                    this.applyDetail(dialog.id, result.item);
                    notify(this.L.edited);
                    editImage = null;
                    this.closeDialog();
                });
            },

            async revertEdit() {
                const dialog = this.dialog;

                await this.busyWhile(async () => {
                    let result;

                    try {
                        result = await wire.revertEdits(dialog.id);
                    } catch (e) {
                        result = null;
                    }

                    if (! result || ! result.ok) {
                        return;
                    }

                    this.applyDetail(dialog.id, result.item);
                    notify(this.L.reverted);
                    editImage = null;
                    this.closeDialog();
                });
            },

            // ---------- Fase 7: de pomp ----------

            runTitle(run) {
                return (this.L.runTypes || {})[run.type] || run.type;
            },

            runProgress(run) {
                return fill(this.L.runProgress, { done: Math.min(run.done, run.total), total: run.total })
                    + (run.failed ? ' · ' + fill(this.L.runFailedCount, { count: run.failed }) : '');
            },

            // Een rustige poll: alleen zolang er een run loopt en de pagina zichtbaar is.
            async loadRunStatus() {
                clearTimeout(runTimer);

                if (! this.manage || document.hidden) {
                    return;
                }

                let result;

                try {
                    result = await wire.loadConversionStatus();
                } catch (e) {
                    result = null;
                }

                if (! result || ! result.ok) {
                    return;
                }

                const wasRunning = !! this.run;

                this.run = result.run;
                this.runFailed = result.failed;
                this.missingFormats = !! result.missing;

                if (this.run) {
                    runTimer = setTimeout(() => this.loadRunStatus(), 4000);
                } else if (wasRunning && this.detailOpen && this.detail.id) {
                    // Klaar: de formaten en de miniatuur van het open bestand zijn nieuw.
                    this.openDetail(this.detail.id);
                }
            },

            askRegenerate(ids) {
                ids = ids || [];

                if (! ids.length) {
                    return;
                }

                this.openDialog({
                    type: 'confirm',
                    title: this.L.regenerateTitle,
                    body: fill(ids.length === 1 ? this.L.regenerateBodyOne : this.L.regenerateBody, { count: ids.length }),
                    action: this.L.regenerate,
                    run: () => this.startRun('force', ids, true),
                });
            },

            async startRun(type, ids, inDialog) {
                const start = async () => {
                    let result;

                    try {
                        result = await wire.startConversions(type, ids || []);
                    } catch (e) {
                        result = null;
                    }

                    if (result && result.ok) {
                        this.run = result.run;
                        this.runFailed = null;
                        this.missingFormats = false;
                        clearTimeout(runTimer);
                        runTimer = setTimeout(() => this.loadRunStatus(), 1500);
                    }
                };

                // Vanuit een bevestiging staat `busy` al aan.
                return inDialog ? start() : this.busyWhile(start);
            },

            async cancelRun() {
                const run = this.run;

                if (! run) {
                    return;
                }

                await this.busyWhile(async () => {
                    try {
                        await wire.cancelConversions(run.id);
                    } catch (e) {
                        // De volgende poll laat zien wat er echt gebeurd is.
                    }
                });

                this.loadRunStatus();
            },

            async resumeRun() {
                const run = this.runFailed;

                if (! run) {
                    return;
                }

                await this.busyWhile(async () => {
                    try {
                        await wire.resumeConversions(run.id);
                    } catch (e) {
                        // Zie hierboven.
                    }
                });

                this.loadRunStatus();
            },

            // ---------- Toetsen, plakken, slepen ----------

            rootKey(event) {
                // De zoekpopup van het paneel luistert op window naar "/" en
                // Cmd/Ctrl+K; vanuit een invoerveld van deze pagina hoort dat niet af te gaan.
                if (isTyping(event) && (event.key === '/' || ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k'))) {
                    event.stopPropagation();

                    return;
                }

                if (event.key !== 'Escape') {
                    return;
                }

                if (this.dialogOpen) {
                    this.closeDialog();
                } else if (this.renaming !== null) {
                    this.renaming = null;
                } else if (this.folderMenu !== null || this.tagMenu) {
                    this.folderMenu = null;
                    this.tagMenu = false;
                } else if (this.treeOpen) {
                    this.treeOpen = false;
                } else if (this.selectedCount && ! this.pick) {
                    this.clearSelection();
                } else {
                    // In het keuzevenster sluit Escape nu het venster zelf.
                    return;
                }

                event.stopPropagation();
            },

            paste(event) {
                if (! this.canUpload || this.dialogOpen) {
                    return;
                }

                // Staat op de beheerpagina het keuzevenster open (een poster
                // kiezen), dan is plakken voor dat venster, niet voor de pagina erachter.
                if (this.manage && window.dashedMedia.hostOpen && window.dashedMedia.hostOpen()) {
                    return;
                }

                const files = Array.from(event.clipboardData?.files || []).filter((file) => (file.type || '').startsWith('image/'));

                if (! files.length) {
                    return;
                }

                event.preventDefault();
                this.queueUploads(files, this.currentFolderId());
            },

            dragItem(event, index) {
                const item = this.items[index];
                const ids = this.isSelected(item.id) ? this.selectedIds : [item.id];

                event.dataTransfer.setData(DRAG_ITEMS, JSON.stringify(ids));
                event.dataTransfer.effectAllowed = 'move';
            },

            dragFolder(event, id) {
                event.dataTransfer.setData(DRAG_FOLDER, String(id));
                event.dataTransfer.effectAllowed = 'move';
            },

            overFolder(event, key) {
                const types = dragTypes(event);

                // In het keuzevenster kun je alleen bestanden van buiten op een map laten vallen.
                if (! (this.manage ? (types.includes(DRAG_ITEMS) || types.includes(DRAG_FOLDER) || types.includes('Files')) : (this.canUpload && types.includes('Files')))) {
                    return;
                }

                event.preventDefault();
                this.dropFolder = key;
            },

            leaveFolder(event, key) {
                if (this.dropFolder === key && ! event.currentTarget.contains(event.relatedTarget)) {
                    this.dropFolder = null;
                }
            },

            // folderId: een id, of null voor "zonder map".
            dropOnFolder(event, folderId) {
                this.dropFolder = null;

                const types = dragTypes(event);

                if (! this.manage) {
                    if (this.canUpload && types.includes('Files')) {
                        event.preventDefault();
                        this.queueUploads(Array.from(event.dataTransfer.files || []), folderId);
                    }

                    return;
                }

                if (types.includes(DRAG_ITEMS)) {
                    event.preventDefault();

                    try {
                        this.moveItemsTo(JSON.parse(event.dataTransfer.getData(DRAG_ITEMS)), folderId);
                    } catch (e) {
                        // Geen geldige lijst: dan was het geen sleep van deze pagina.
                    }
                } else if (types.includes(DRAG_FOLDER)) {
                    event.preventDefault();

                    const id = Number(event.dataTransfer.getData(DRAG_FOLDER));

                    if (id && id !== folderId) {
                        this.moveFolderTo(id, folderId);
                    }
                } else if (types.includes('Files')) {
                    event.preventDefault();
                    this.queueUploads(Array.from(event.dataTransfer.files || []), folderId);
                }
            },

            overGrid(event) {
                if (this.canUpload && dragTypes(event).includes('Files')) {
                    event.preventDefault();
                    this.gridDrop = true;
                }
            },

            leaveGrid(event) {
                if (! event.currentTarget.contains(event.relatedTarget)) {
                    this.gridDrop = false;
                }
            },

            dropOnGrid(event) {
                this.gridDrop = false;

                if (this.canUpload && dragTypes(event).includes('Files')) {
                    event.preventDefault();
                    this.queueUploads(Array.from(event.dataTransfer.files || []), this.currentFolderId());
                }
            },

            // ---------- Uploaden ----------

            currentFolderId() {
                return typeof this.filters.folder === 'number' ? this.filters.folder : null;
            },

            pickFiles() {
                ref('file').value = '';
                ref('file').click();
            },

            picked(event) {
                this.queueUploads(Array.from(event.target.files || []), this.currentFolderId());
            },

            queueUploads(files, folderId) {
                if (this.pick) {
                    // Wat het veld niet aanneemt hoeft de bibliotheek niet in,
                    // en een enkel veld neemt één bestand.
                    const accepted = files.filter((file) => this.accepts(file));

                    if (accepted.length < files.length) {
                        notify(this.PL.notAccepted, 'danger');
                    }

                    files = this.pick.multiple ? accepted : accepted.slice(0, 1);
                }

                files.forEach((file, index) => {
                    const key = 'u' + Date.now().toString(36) + index + Math.random().toString(36).slice(2, 8);

                    const name = file.name || this.L.pastedName || 'upload';

                    // Wat de uploadroute toch weigert hoeft de lijn niet over.
                    if (this.tooLarge(file)) {
                        this.uploads.push({ key, name, progress: 0, status: 'error', message: this.tooLargeLabel(), folderId, onDuplicate: 'reuse', canAddAnyway: false });

                        return;
                    }

                    pendingFiles.set(key, file);
                    this.uploads.push({ key, name, progress: 0, status: 'queued', message: '', folderId, onDuplicate: 'reuse', canAddAnyway: false });
                });

                this.pumpUploads();
            },

            tooLarge(file) {
                const max = Number(this.config.uploadMaxKb) || 0;

                return max > 0 && file.size > max * 1024;
            },

            tooLargeLabel() {
                const mb = (Number(this.config.uploadMaxKb) || 0) / 1024;

                return fill(this.L.uploadTooLarge, { max: mb >= 10 ? Math.floor(mb) : Math.max(0.1, Math.round(mb * 10) / 10) });
            },

            // Wat er misging op de uploadroute: de reden van de server als die
            // er is, anders "te groot" als de maat dat verklaart, anders algemeen.
            uploadError(key, file) {
                const refusal = refusals.get('uploads.' + key);

                refusals.delete('uploads.' + key);

                if (refusal && refusal.message) {
                    return refusal.message;
                }

                if (file && this.tooLarge(file)) {
                    return this.tooLargeLabel();
                }

                return refusal && refusal.refused ? this.L.uploadRefused : this.L.uploadFailed;
            },

            entry(key) {
                return this.uploads.find((upload) => upload.key === key);
            },

            pumpUploads() {
                while (activeUploads < MAX_PARALLEL_UPLOADS) {
                    const next = this.uploads.find((upload) => upload.status === 'queued');

                    if (! next) {
                        break;
                    }

                    this.startUpload(next.key);
                }

                if (activeUploads === 0 && this.uploads.length && ! this.uploads.some((upload) => upload.status === 'queued')) {
                    const done = this.uploads.filter((upload) => upload.status === 'done' || upload.status === 'duplicate').length;

                    this.live = fill(this.L.uploadsDone, { done, total: this.uploads.length });
                    // De aantallen in de boom zijn veranderd.
                    this.loadTree();

                    // Klaar met uploaden: terug naar de lijst, waar het nieuwe geselecteerd staat.
                    if (this.pick && this.pickTab === 'upload' && done > 0) {
                        this.pickTab = 'library';
                    }
                }
            },

            startUpload(key) {
                const file = pendingFiles.get(key);
                const folderId = this.entry(key).folderId;
                const finish = (status, message) => {
                    const entry = this.entry(key);

                    if (entry) {
                        entry.status = status;
                        entry.message = message || '';
                        entry.progress = 100;
                    }

                    pendingFiles.delete(key);
                    activeUploads--;
                    this.pumpUploads();
                };

                activeUploads++;
                this.entry(key).status = 'uploading';

                // Alleen als de server zelf geen posters maakt: tijdens het uploaden alvast een beeld uit de video.
                const posterOf = this.config.browserPosters ? videoPoster(file) : null;

                wire.upload(
                    'uploads.' + key,
                    file,
                    async () => {
                        this.entry(key).status = 'processing';

                        let result;

                        try {
                            const posterKey = posterOf ? await uploadPoster(wire, key, posterOf) : null;

                            result = (await wire.storeUploads([key], folderId, (this.entry(key) || {}).onDuplicate || 'reuse', posterKey ? { [key]: posterKey } : {}))[key];
                        } catch (e) {
                            result = null;
                        }

                        if (! result || ! result.ok) {
                            finish('error', (result && result.error) || this.L.uploadFailed);

                            return;
                        }

                        // Een duplicaat dat gewoon bestond kan alsnog als nieuw
                        // bestand erbij; een dat uit de prullenbak kwam is al terug.
                        if (result.duplicate && ! result.restored && this.manage && this.entry(key)) {
                            duplicateFiles.set(key, file);
                            this.entry(key).canAddAnyway = true;
                        }

                        this.showUploaded(result.item, result.duplicate);
                        finish(result.duplicate ? 'duplicate' : 'done', result.restored ? this.L.restoredFromTrash : (result.duplicate ? this.L.duplicate : this.L.uploaded));
                    },
                    () => finish('error', this.uploadError(key, file)),
                    (event) => {
                        const entry = this.entry(key);

                        if (entry) {
                            entry.progress = event.detail?.progress || 0;
                        }
                    },
                );
            },

            showUploaded(item, duplicate) {
                this.items = [item, ...this.items.filter((entry) => entry.id !== item.id)];

                if (this.total !== null && ! duplicate) {
                    this.total++;
                }

                // De indexen zijn opgeschoven; het anker hoort bij de oude volgorde.
                this.anchor = null;

                // In het keuzevenster staat wat je net uploadde meteen geselecteerd.
                if (this.pick) {
                    this.focusIndex = 0;
                    this.pickToggle(item.id, true);

                    return;
                }

                if (duplicate) {
                    this.focusIndex = 0;
                    this.setSelection([item.id]);
                }
            },

            clearUploads() {
                this.uploads = this.uploads.filter((upload) => ['queued', 'uploading', 'processing'].includes(upload.status));
                duplicateFiles.clear();
            },

            // "Toch als nieuw bestand toevoegen": hetzelfde bestand nog een keer, nu zonder dubbelcontrole.
            addAnyway(key) {
                const file = duplicateFiles.get(key);
                const entry = this.entry(key);

                if (! file || ! entry) {
                    return;
                }

                duplicateFiles.delete(key);
                entry.canAddAnyway = false;

                const again = 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);

                pendingFiles.set(again, file);
                this.uploads.push({ key: again, name: entry.name, progress: 0, status: 'queued', message: '', folderId: entry.folderId, onDuplicate: 'create', canAddAnyway: false });
                this.pumpUploads();
            },

            get uploadsBusy() {
                return this.uploads.some((upload) => ['queued', 'uploading', 'processing'].includes(upload.status));
            },

            // ---------- Weergave ----------

            statusLabel(upload) {
                return upload.message || this.L[upload.status] || '';
            },

            typeLabel(item) {
                return (this.L.types || {})[item.type] || item.type;
            },

            extension(item) {
                const name = item.file_name || '';
                const dot = name.lastIndexOf('.');

                return dot > 0 ? name.slice(dot + 1).toLowerCase() : '';
            },

            dimensions(item) {
                return item.width && item.height ? item.width + ' × ' + item.height : '';
            },

            // ---------- Video en pdf (fase 9b) ----------

            // Seconden als 0:07, 3:25 of 1:02:03.
            duration(seconds) {
                const total = Math.round(Number(seconds) || 0);

                if (total <= 0) {
                    return '';
                }

                const hours = Math.floor(total / 3600);
                const minutes = Math.floor((total % 3600) / 60);
                const rest = String(total % 60).padStart(2, '0');

                return hours > 0 ? hours + ':' + String(minutes).padStart(2, '0') + ':' + rest : minutes + ':' + rest;
            },

            pagesLabel(count) {
                const pages = Number(count) || 0;

                return pages <= 0 ? '' : (pages === 1 ? (this.L.onePage || '1') : fill(this.L.pages || ':count', { count: pages }));
            },

            // De tegel op een scherm met dubbele dichtheid: de gewone miniatuur (600) in plaats van de beheerminiatuur (320). In de lijst (48 px) is 320 al ruim genoeg.
            thumbSrcset(item) {
                if (this.view === 'list' || ! item.thumb_small || ! item.thumb || item.thumb_small === item.thumb) {
                    return null;
                }

                return item.thumb_small + ' 1x, ' + item.thumb + ' 2x';
            },

            // Op de tegel: de duur van een video of het aantal pagina's van een pdf.
            previewBadge(item) {
                return item.type === 'video' ? this.duration(item.duration) : this.pagesLabel(item.pages);
            },

            // In de lijst: afmetingen, met de duur of het aantal pagina's erbij.
            metaLabel(item) {
                return [this.dimensions(item), this.previewBadge(item)].filter(Boolean).join(' · ');
            },

            posterLabel(item) {
                if (item.poster && item.poster.custom) {
                    return this.L.posterCustom || '';
                }

                return item.poster || item.has_preview ? (this.L.posterAuto || '') : (this.L.posterNone || '');
            },

            // Opent het keuzevenster, beperkt tot afbeeldingen. De makelaar
            // meldt de keuze terug via een callback: deze pagina is zelf
            // geen formulierveld.
            choosePoster() {
                const id = this.detail.id;
                const dm = window.dashedMedia;

                if (! this.detailOpen || ! id || ! dm || ! dm.pick) {
                    return;
                }

                dm.pick({
                    accept: ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
                    multiple: false,
                    selected: this.detail.poster && this.detail.poster.item_id ? [this.detail.poster.item_id] : [],
                    remember: 'poster@' + window.location.pathname,
                    callback: (ids) => this.savePoster(id, ids[0] || null),
                });
            },

            removePoster() {
                if (this.detailOpen && this.detail.id) {
                    this.savePoster(this.detail.id, null);
                }
            },

            // False houdt het keuzevenster open: er kan dan iets anders gekozen worden.
            async savePoster(id, posterId) {
                this.busy = true;

                let result;

                try {
                    result = await wire.setPoster(id, posterId);
                } catch (e) {
                    result = null;
                } finally {
                    this.busy = false;
                }

                if (! result || ! result.ok) {
                    return false;
                }

                this.patchItem(id, result.item);
                notify(posterId ? this.L.posterSaved : this.L.posterRemoved);

                return true;
            },

            async regeneratePreview() {
                const id = this.detail.id;

                if (! this.detailOpen || ! id) {
                    return;
                }

                this.busy = true;

                let result;

                try {
                    result = await wire.regeneratePreview(id);
                } catch (e) {
                    result = null;
                } finally {
                    this.busy = false;
                }

                if (result && result.ok) {
                    notify(result.message);

                    // De taak loopt in de wachtrij: even later het paneel opnieuw.
                    setTimeout(() => {
                        if (this.detailOpen && this.detail.id === id) {
                            this.openDetail(id);
                        }
                    }, 4000);
                }
            },

            size(bytes) {
                if (bytes === null || bytes === undefined) {
                    return '';
                }

                const units = ['B', 'kB', 'MB', 'GB'];
                let value = Number(bytes);
                let unit = 0;

                while (value >= 1024 && unit < units.length - 1) {
                    value /= 1024;
                    unit++;
                }

                return (unit === 0 ? value : value.toFixed(value < 10 ? 1 : 0)) + ' ' + units[unit];
            },

            date(iso) {
                if (! iso) {
                    return '';
                }

                try {
                    return new Intl.DateTimeFormat(this.config.locale || undefined, { dateStyle: 'medium' }).format(new Date(iso));
                } catch (e) {
                    return String(iso).slice(0, 10);
                }
            },

            // Alleen typen die doorzichtig kunnen zijn krijgen het dambord.
            transparent(item) {
                return ['image/png', 'image/webp', 'image/gif', 'image/svg+xml', 'image/avif'].includes(item.mime || '');
            },

            // Leeg in minstens één taal van de site. Bij één taal is dat gewoon "geen alt-tekst".
            needsAlt(item) {
                if (item.type !== 'image' && item.type !== 'svg') {
                    return false;
                }

                return Array.isArray(item.alt_missing) ? item.alt_missing.length > 0 : ! item.alt;
            },

            // Welke talen missen, alleen als het er niet allemaal zijn.
            altMissingLocales(item) {
                const missing = Array.isArray(item.alt_missing) ? item.alt_missing : [];

                return this.locales.length > 1 && missing.length > 0 && missing.length < this.locales.length
                    ? missing.map((locale) => String(locale).toUpperCase()).join(', ')
                    : '';
            },

            altFlagText(item) {
                const locales = this.altMissingLocales(item);

                return locales ? (this.L.noAltShort || '') + ': ' + locales : (this.L.noAltShort || '');
            },

            altFlagTitle(item) {
                const locales = this.altMissingLocales(item);

                return locales ? String(this.L.noAltIn || '').replace(':locales', locales) : (this.L.noAltTitle || '');
            },

            confirmPick() {
                // In de volgorde waarin er gekozen is, niet op id.
                const ids = this.pick ? this.pickOrder.filter((id) => this.selected[id]) : this.selectedIds;

                if (! ids.length) {
                    return;
                }

                root.dispatchEvent(new CustomEvent('dashed-media-picked', { bubbles: true, detail: { ids } }));
            },

            // ---------- Externe bronnen (fase 11) ----------

            // Alleen een https-adres wordt ooit een link of een src; al het andere is niets.
            safeHref(url) {
                return typeof url === 'string' && /^https:\/\/[^\s"'<>]+$/i.test(url) ? url : null;
            },

            photoBy(result) {
                return fill(this.SL.photoBy || ':name', { name: (result && result.author) || '' });
            },

            openSource(key) {
                const found = this.sources.find((entry) => entry.key === key);

                if (! found || ! this.canUpload) {
                    return;
                }

                // Op de beheerpagina sluit een tweede klik op dezelfde knop het paneel weer.
                if (this.manage && this.sourceKey === key) {
                    this.closeSource();

                    return;
                }

                if (this.sourceKey !== key) {
                    this.sourceResults = [];
                    this.sourcePage = 0;
                    this.sourceHasMore = false;
                    this.sourceQuery = '';
                    this.setSourceMessage('', '');
                }

                this.sourceKey = key;
                this.source = found;

                if (found.kind === 'generate' && ! (found.ratios || []).some((option) => option.value === this.sourceForm.ratio)) {
                    this.sourceForm.ratio = ((found.ratios || [])[0] || {}).value || '1:1';
                }

                if (this.pick) {
                    this.pickTab = 'source';
                }

                this.$nextTick(() => {
                    const field = root.querySelector('[data-media-source-panel] ' + (found.kind === 'url' ? '[data-media-source-url]' : (found.kind === 'search' ? '[data-media-source-query]' : '[data-media-source-prompt]')));

                    if (field) {
                        field.focus();
                    }
                });
            },

            closeSource() {
                this.sourceKey = null;
                this.source = {};

                if (this.pick && this.pickTab === 'source') {
                    this.setPickTab('library');
                }
            },

            setSourceMessage(text, state) {
                this.sourceMessage = text || '';
                this.sourceState = state || '';

                if (text) {
                    this.live = text;
                }
            },

            // Wat er is binnengekomen staat vooraan in de lijst; in het keuzevenster staat het meteen gekozen.
            sourceArrived(result) {
                this.showUploaded(result.item, !! result.duplicate);
                this.loadTree();
                this.setSourceMessage(result.restored ? this.L.restoredFromTrash : (result.duplicate ? this.SL.existed : this.SL.imported), 'done');

                if (this.pick) {
                    this.pickTab = 'library';
                }
            },

            async sourceImportUrl() {
                const url = (this.sourceForm.url || '').trim();

                if (! url || this.sourceBusy) {
                    return;
                }

                this.sourceBusy = true;
                this.setSourceMessage(this.SL.importing, 'busy');

                let result;

                try {
                    result = await wire.sourceImportUrl(url, this.currentFolderId());
                } catch (e) {
                    result = null;
                }

                this.sourceBusy = false;

                if (! result || ! result.ok) {
                    this.setSourceMessage((result && result.error) || this.SL.failed, 'error');

                    return;
                }

                this.sourceForm.url = '';
                this.sourceArrived(result);
            },

            async sourceSearch(page) {
                const key = this.sourceKey;
                const query = page > 1 ? this.sourceQuery : (this.sourceForm.query || '').trim();

                if (! key || ! query || this.sourceBusy) {
                    return;
                }

                this.sourceBusy = true;
                this.setSourceMessage(this.SL.searching, 'busy');

                let result;

                try {
                    result = await wire.sourceSearch(key, query, page, { orientation: this.sourceForm.orientation || null });
                } catch (e) {
                    result = null;
                }

                this.sourceBusy = false;

                // Intussen een andere bron geopend: dit antwoord hoort daar niet.
                if (this.sourceKey !== key) {
                    return;
                }

                if (! result || ! result.ok) {
                    this.setSourceMessage((result && result.error) || this.SL.failed, 'error');

                    return;
                }

                const fresh = (result.items || []).map((item) => Object.assign({ busy: false, done: false }, item));

                this.sourceResults = page > 1 ? this.sourceResults.concat(fresh.filter((item) => ! this.sourceResults.some((known) => known.id === item.id))) : fresh;
                this.sourcePage = result.page || page;
                this.sourceHasMore = !! result.hasMore;
                this.sourceQuery = query;
                this.setSourceMessage(this.sourceResults.length ? '' : this.SL.noResults, '');
            },

            async sourceImport(result) {
                const key = this.sourceKey;

                if (! key || ! result || result.busy || result.done) {
                    return;
                }

                result.busy = true;

                let answer;

                try {
                    answer = await wire.sourceImport(key, String(result.id), this.sourceQuery, this.currentFolderId());
                } catch (e) {
                    answer = null;
                }

                result.busy = false;

                if (! answer || ! answer.ok) {
                    this.setSourceMessage((answer && answer.error) || this.SL.failed, 'error');

                    return;
                }

                result.done = true;
                this.sourceArrived(answer);
            },

            useReference() {
                if (this.detailOpen && this.detail.id && this.detail.type === 'image' && ! this.detail.private) {
                    this.sourceReference = { id: this.detail.id, name: this.detail.name || this.detail.file_name || ('#' + this.detail.id) };
                }
            },

            async sourceGenerate() {
                const prompt = (this.sourceForm.prompt || '').trim();

                if (! prompt || this.sourceBusy) {
                    return;
                }

                this.sourceBusy = true;
                this.setSourceMessage(this.SL.queued, 'busy');

                let result;

                try {
                    result = await wire.sourceGenerate(prompt, this.sourceForm.ratio || '1:1', this.sourceReference ? this.sourceReference.id : null, this.currentFolderId());
                } catch (e) {
                    result = null;
                }

                if (! result || ! result.ok || ! result.request) {
                    this.sourceBusy = false;
                    this.setSourceMessage((result && result.error) || this.SL.failed, 'error');

                    return;
                }

                this.pollSource(result.request, ++sourceSeq, 0);
            },

            // Spaarzaam: eerst na twee seconden, daarna elke vier, hooguit tien minuten. Stopt als het venster sluit.
            pollSource(request, token, tries) {
                clearTimeout(sourceTimer);

                sourceTimer = setTimeout(async () => {
                    if (token !== sourceSeq) {
                        return;
                    }

                    let result;

                    try {
                        result = await wire.sourceStatus(request);
                    } catch (e) {
                        result = null;
                    }

                    if (token !== sourceSeq) {
                        return;
                    }

                    if (result && result.ok && result.status === 'done' && result.item) {
                        this.sourceBusy = false;
                        this.sourceForm.prompt = '';
                        this.sourceArrived({ item: result.item, duplicate: false });
                        this.setSourceMessage(this.SL.generated, 'done');

                        return;
                    }

                    if ((result && (! result.ok || result.status === 'failed')) || tries >= 150) {
                        this.sourceBusy = false;
                        this.setSourceMessage((result && result.error) || this.SL.failed, 'error');

                        return;
                    }

                    if (result && result.status === 'processing') {
                        this.setSourceMessage(this.SL.processing, 'busy');
                    }

                    this.pollSource(request, token, tries + 1);
                }, tries === 0 ? 2000 : 4000);
            },

            // Zet "Foto van <maker> via <aanbieder>" in het bijschrift van de taal die open staat. De beheerder kan het daarna aanpassen.
            creditToCaption() {
                const credit = this.detail.origin && this.detail.origin.credit;

                if (! credit || ! this.manage || ! this.canEdit) {
                    return;
                }

                this.form.caption = credit;
                this.saveField('caption');
                notify(this.SL.originCopied);
            },
        };
    };

    window.dashedMedia = { browser, DRAG_ITEMS, DRAG_FOLDER, videoPoster, uploadPoster };

    const register = () => window.Alpine.data('dashedMediaBrowser', browser);

    // Bij een gewone paginalading bestaat Alpine nog niet; na wire:navigate wel.
    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register);
    }
})();

(() => {
    const dm = window.dashedMedia;

    if (! dm || dm.pick) {
        return;
    }

    const SORT = 'application/x-dashed-media-field-sort';
    const MAX_PARALLEL = 3;

    let host = null;
    let session = null;
    let guarding = false;
    // De markup van de lijst in het keuzevenster: één keer opgehaald, daarna
    // per opening hergebruikt (alleen de instellingen verschillen).
    let template = null;
    let openSeq = 0;

    const notify = (title, status = 'success') => {
        if (! window.FilamentNotification || ! title) {
            return;
        }

        new window.FilamentNotification().title(title)[status]().send();
    };

    const fill = (text, values) => Object.keys(values).reduce((out, key) => out.split(':' + key).join(String(values[key])), String(text || ''));

    const decode = (value) => {
        try {
            const bytes = Uint8Array.from(atob(value || ''), (c) => c.charCodeAt(0));

            return JSON.parse(new TextDecoder().decode(bytes) || '{}');
        } catch (e) {
            return {};
        }
    };

    const accepts = (file, accept) => ! accept || ! accept.length || accept.some((type) => type.endsWith('/*')
        ? (file.type || '').toLowerCase().startsWith(type.slice(0, -1))
        : (file.type || '').toLowerCase() === type);

    // De sneltoetsen van de zoekpopup van het paneel ("/" en Cmd/Ctrl+K)
    // luisteren op window; zolang het venster open staat komen ze daar niet aan.
    const guard = (event) => {
        if (event.key === '/' || ((event.metaKey || event.ctrlKey) && String(event.key).toLowerCase() === 'k')) {
            event.stopPropagation();
        }
    };

    const setGuard = (on) => {
        if (on === guarding) {
            return;
        }

        guarding = on;
        document[on ? 'addEventListener' : 'removeEventListener']('keydown', guard, true);
    };

    const fieldWire = (livewireId) => {
        try {
            return (window.Livewire && window.Livewire.find(livewireId)) || null;
        } catch (e) {
            return null;
        }
    };

    // true: gezet. false: het veld weigerde en meldde zelf waarom. Al het
    // andere: het veld is er niet meer (een dichtgeklapt blok, een gesloten modal).
    const apply = async (target, ids, append) => {
        const wire = fieldWire(target.livewireId);
        let result = null;

        if (wire) {
            try {
                result = await wire.callSchemaComponentMethod(target.key, 'applySelection', { ids, append: !! append });
            } catch (e) {
                result = null;
            }
        }

        if (result !== true && result !== false) {
            notify((target.labels || {}).gone, 'warning');
        }

        return result;
    };

    const mountOf = () => (host ? host.el.querySelector('[data-dashed-media-pick-mount]') : null);

    // Terug naar de wachtmelding; de lijst zelf wordt daarmee door Alpine opgeruimd.
    const resetMount = () => {
        const mount = mountOf();

        if (mount && host.wait) {
            mount.replaceChildren(host.wait.cloneNode(true));
        }
    };

    // Zet de lijst in het venster: de bewaarde markup met de instellingen van deze opening.
    const showList = (seq, result) => {
        if (! host || ! host.open || seq !== openSeq || ! result || ! result.config) {
            return;
        }

        if (typeof result.html === 'string') {
            template = result.html;
        }

        const mount = mountOf();

        if (! template || ! mount) {
            return;
        }

        const holder = document.createElement('template');

        holder.innerHTML = template;

        const root = holder.content.querySelector('[data-dashed-media]');

        if (! root) {
            return;
        }

        root.setAttribute('data-dashed-media', JSON.stringify(result.config));
        mount.replaceChildren(holder.content);
    };

    const closeModal = () => {
        if (host) {
            window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: host.id } }));
        }
    };

    Object.assign(dm, {
        registerHost(el, wire) {
            // Een nieuwe gastheer (na wire:navigate) begint zonder open venster.
            setGuard(false);
            session = null;
            const mount = el.querySelector('[data-dashed-media-pick-mount]');

            host = { el, wire, id: el.dataset.dashedMediaPickerHost, open: false, wait: mount && mount.firstElementChild ? mount.firstElementChild.cloneNode(true) : null };
        },

        available() {
            return !! host;
        },

        // Of het keuzevenster nu open staat.
        hostOpen() {
            return !! (host && host.open);
        },

        /**
         * Opent het keuzevenster voor een veld.
         * request: { key, livewireId, accept, multiple, max, folder, folderPath, selected, remember, labels }
         *
         * Een aanroeper die zelf geen formulierveld is (de beheerpagina die
         * een poster laat kiezen) geeft in plaats van key en livewireId een
         * `callback(ids)` mee. Geeft die false terug, dan blijft het venster open.
         */
        pick(request) {
            const callback = request && typeof request.callback === 'function' ? request.callback : null;

            if (! host || ! request || (! callback && (! request.key || ! request.livewireId))) {
                return false;
            }

            session = {
                key: request.key || null,
                livewireId: request.livewireId || null,
                multiple: !! request.multiple,
                labels: request.labels || {},
                callback,
            };

            host.open = true;
            setGuard(true);
            resetMount();
            window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: host.id } }));

            const seq = ++openSeq;

            host.wire.openPicker({
                // De markup is al binnen: de server stuurt hem dan niet nog eens mee.
                template: template !== null,
                accept: request.accept || [],
                multiple: !! request.multiple,
                max: request.max || null,
                folder: request.folder || null,
                folderPath: request.folderPath || null,
                selected: request.selected || [],
                remember: request.remember || null,
                // Fase 10: privé alleen voor een veld met allowPrivate(), en de sites van het record als standaardfilter.
                allowPrivate: !! request.allowPrivate,
                sites: request.sites || [],
                // Fase 11: de bronnen die het veld toelaat (null is alles) en het tabblad waarop het venster opent.
                sources: request.sources === undefined ? null : request.sources,
                source: request.source || null,
                // De handtekening van de server over deze instellingen; zonder geldige geen bronnen en geen privé.
                signature: request.signature || null,
            }).then((result) => showList(seq, result)).catch(() => closeModal());

            return true;
        },

        async hostPicked(event) {
            const target = session;
            const ids = (event.detail && event.detail.ids) || [];

            if (! target || ! ids.length) {
                return;
            }

            let result;

            if (target.callback) {
                try {
                    result = await target.callback(ids);
                } catch (e) {
                    result = null;
                }
            } else {
                // Een meervoudig veld vult aan wat er al staat; een enkel veld vervangt.
                result = await apply(target, ids, target.multiple);
            }

            // Geweigerd: het venster blijft open, zodat er iets anders gekozen kan worden.
            if (result !== false) {
                closeModal();
            }
        },

        hostClose() {
            closeModal();
        },

        hostClosed(event) {
            if (! host || ! event.detail || event.detail.id !== host.id || ! host.open) {
                return;
            }

            host.open = false;
            session = null;
            setGuard(false);
            resetMount();
            host.wire.closePicker();
        },

        /**
         * Uploadt bestanden naar de bibliotheek zonder het venster te openen
         * en zet ze daarna op het veld.
         * request: { key, livewireId, folder, folderPath, accept, multiple, labels, onProgress }
         */
        async upload(files, request) {
            if (! host || ! request || ! request.key || ! request.livewireId) {
                return false;
            }

            const labels = request.labels || {};
            const progress = typeof request.onProgress === 'function' ? request.onProgress : () => {};
            let list = Array.from(files || []);
            const accepted = list.filter((file) => accepts(file, request.accept));

            if (accepted.length < list.length) {
                notify(labels.notAccepted, 'danger');
            }

            if (! request.multiple && accepted.length > 1) {
                notify(labels.onlyOne, 'warning');
            }

            list = request.multiple ? accepted : accepted.slice(0, 1);

            const maxKb = Number(host.el.dataset.uploadMaxKb) || 0;
            const fits = list.filter((file) => ! maxKb || file.size <= maxKb * 1024);

            if (fits.length < list.length) {
                notify(fill(labels.tooLarge, { max: Math.max(1, Math.floor(maxKb / 1024)) }), 'danger');
            }

            list = fits;

            if (! list.length) {
                return false;
            }

            progress(0);

            let folderId = request.folder || null;

            if (! folderId && request.folderPath) {
                try {
                    folderId = await host.wire.folderForPath(request.folderPath);
                } catch (e) {
                    folderId = null;
                }
            }

            const done = {};
            const percent = {};
            const report = () => progress(Math.round(Object.values(percent).reduce((a, b) => a + b, 0) / list.length));

            const one = (file, index) => new Promise((resolve) => {
                const key = 'f' + Date.now().toString(36) + index + Math.random().toString(36).slice(2, 8);

                percent[index] = 0;

                // Fase 9b: zonder ffmpeg op de server maakt de browser zelf een poster van een video.
                const posterOf = host.el.dataset.browserPosters === '1' && dm.videoPoster ? dm.videoPoster(file) : null;

                host.wire.upload(
                    'uploads.' + key,
                    file,
                    async () => {
                        let result = null;

                        try {
                            const posterKey = posterOf && dm.uploadPoster ? await dm.uploadPoster(host.wire, key, posterOf) : null;

                            result = (await host.wire.storeUploads([key], folderId, 'reuse', posterKey ? { [key]: posterKey } : {}))[key];
                        } catch (e) {
                            result = null;
                        }

                        if (result && result.ok && result.item) {
                            done[index] = result.item.id;
                        } else {
                            notify((result && result.error) || labels.failed, 'danger');
                        }

                        percent[index] = 100;
                        report();
                        resolve();
                    },
                    () => {
                        notify(labels.failed, 'danger');
                        percent[index] = 100;
                        report();
                        resolve();
                    },
                    (event) => {
                        percent[index] = Math.min(99, (event.detail && event.detail.progress) || 0);
                        report();
                    },
                );
            });

            // Een paar tegelijk, in de volgorde van de lijst.
            let next = 0;
            const worker = async () => {
                while (next < list.length) {
                    const index = next++;

                    await one(list[index], index);
                }
            };

            await Promise.all(Array.from({ length: Math.min(MAX_PARALLEL, list.length) }, worker));

            progress(null);

            const ids = list.map((file, index) => done[index]).filter(Boolean);

            if (! ids.length) {
                return false;
            }

            const applied = await apply({ key: request.key, livewireId: request.livewireId, labels }, ids, !! request.multiple);

            // Geüpload is geüpload: weigert het veld daarna alsnog (of is het
            // weg), dan staat het bestand wel in de bibliotheek.
            if (applied !== true) {
                notify(labels.orphaned, 'warning');
            }

            return applied === true;
        },
    });

    // Bij wire:navigate verdwijnt de pagina zonder dat het venster "dicht" meldt.
    document.addEventListener('livewire:navigating', () => {
        setGuard(false);
        session = null;

        if (host) {
            host.open = false;
        }
    });

    // ---------- Het veld ----------

    const rootOf = (el) => (el && el.closest ? el.closest('[data-dashed-media-field]') : null);

    const context = (el) => {
        const root = rootOf(el);

        if (! root) {
            return null;
        }

        const config = decode(root.dataset.dashedMediaField);
        const component = root.closest('[wire\\:id]');
        const livewireId = config.livewireId || (component ? component.getAttribute('wire:id') : null);

        if (! livewireId || ! config.key) {
            return null;
        }

        return { root, config, livewireId };
    };

    const call = async (ctx, method, args) => {
        const wire = fieldWire(ctx.livewireId);

        if (! wire) {
            return null;
        }

        try {
            return await wire.callSchemaComponentMethod(ctx.config.key, method, args || {});
        } catch (e) {
            return null;
        }
    };

    const order = (root) => Array.from(root.querySelectorAll('[data-media-id]')).map((node) => Number(node.dataset.mediaId));

    const status = (root, text) => {
        const node = root.querySelector('[data-dashed-media-status]');

        if (node) {
            node.textContent = text || '';
        }

        root.classList.toggle('dashed-media-field--busy', !! text);
    };

    const hasFiles = (event) => Array.from((event.dataTransfer && event.dataTransfer.types) || []).includes('Files');

    const isTyping = (event) => {
        const el = event.target;

        return !! el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
    };

    const uploadTo = (ctx, files) => {
        const c = ctx.config;

        if (! c.canUpload) {
            return;
        }

        // Vóór het uploaden: wat het veld toch zou weigeren hoort niet eerst
        // als wees in de bibliotheek te belanden.
        const fitting = files.filter((file) => accepts(file, c.accept)).length;

        if (c.multiple && c.max && (c.selected || []).length + fitting > c.max) {
            notify(fill((c.labels || {}).tooMany, { max: c.max }), 'danger');

            return;
        }

        dm.upload(files, {
            key: c.key,
            livewireId: ctx.livewireId,
            folder: c.folder,
            folderPath: c.folderPath,
            accept: c.accept,
            multiple: c.multiple,
            labels: c.labels,
            onProgress: (percent) => status(ctx.root, percent === null ? '' : fill((c.labels || {}).uploading, { percent })),
        });
    };

    dm.field = {
        // De knop "Genereer met AI" naast het label staat buiten het veld zelf:
        // zoek het veld in dezelfde veldomlijsting en open het venster op die bron.
        pickSource(el, source) {
            const wrapper = el && el.closest ? el.closest('.fi-fo-field, .fi-fo-field-wrp, [data-field-wrapper]') : null;
            const field = wrapper ? wrapper.querySelector('[data-dashed-media-field]') : null;

            if (field) {
                dm.field.pick(field, source);
            }
        },

        pick(el, source) {
            const ctx = context(el);

            if (! ctx) {
                return;
            }

            const c = ctx.config;

            dm.pick({
                key: c.key,
                livewireId: ctx.livewireId,
                accept: c.accept,
                multiple: c.multiple,
                max: c.max,
                folder: c.folder,
                folderPath: c.folderPath,
                selected: c.selected,
                // De laatst gebruikte map hoort bij dit veld op deze pagina.
                remember: (c.name || c.statePath || '') + '@' + window.location.pathname,
                labels: c.labels,
                allowPrivate: !! c.allowPrivate,
                sites: c.sites || [],
                sources: c.sources === undefined ? null : c.sources,
                source: typeof source === 'string' ? source : null,
                signature: c.signature || null,
            });
        },

        // Haalt een gekozen bestand terug uit de prullenbak van de bibliotheek.
        restore(el, id) {
            const ctx = context(el);

            if (ctx) {
                call(ctx, 'restoreItem', { id });
            }
        },

        remove(el, id) {
            const ctx = context(el);

            return ctx ? call(ctx, 'removeItem', { id }) : null;
        },

        clear(el) {
            const ctx = context(el);

            return ctx ? call(ctx, 'clearSelection') : null;
        },

        // delta -1: naar voren, +1: naar achteren.
        move(el, id, delta) {
            const ctx = context(el);

            if (! ctx) {
                return null;
            }

            const ids = order(ctx.root);
            const from = ids.indexOf(id);
            const to = from + delta;

            if (from < 0 || to < 0 || to >= ids.length) {
                return null;
            }

            ids.splice(to, 0, ids.splice(from, 1)[0]);

            return call(ctx, 'reorder', { ids });
        },

        saveAlt(el, id, value) {
            const ctx = context(el);

            return ctx ? call(ctx, 'updateAlt', { id, alt: value }) : null;
        },

        sortStart(event, id) {
            const root = rootOf(event.target);

            if (! root || ! event.dataTransfer) {
                return;
            }

            event.dataTransfer.setData(SORT, JSON.stringify({ id, field: root.dataset.dashedMediaField }));
            event.dataTransfer.effectAllowed = 'move';
        },

        sortOver(event) {
            if (Array.from((event.dataTransfer && event.dataTransfer.types) || []).includes(SORT)) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
            }
        },

        sortDrop(event, targetId) {
            const ctx = context(event.target);

            if (! ctx || ! Array.from((event.dataTransfer && event.dataTransfer.types) || []).includes(SORT)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            let data = {};

            try {
                data = JSON.parse(event.dataTransfer.getData(SORT) || '{}');
            } catch (e) {
                data = {};
            }

            // Alleen binnen hetzelfde veld.
            if (data.field !== ctx.root.dataset.dashedMediaField || ! data.id || data.id === targetId) {
                return;
            }

            const ids = order(ctx.root);
            const from = ids.indexOf(data.id);
            const to = ids.indexOf(targetId);

            if (from < 0 || to < 0) {
                return;
            }

            ids.splice(to, 0, ids.splice(from, 1)[0]);
            call(ctx, 'reorder', { ids });
        },

        // Geeft terug of het veld als doel gemarkeerd moet worden.
        fileOver(event, el) {
            const ctx = context(el);

            if (! ctx || ! ctx.config.canUpload || ! hasFiles(event)) {
                return false;
            }

            event.preventDefault();

            return true;
        },

        fileLeave(event, el) {
            return !! (event.relatedTarget && el.contains(event.relatedTarget));
        },

        fileDrop(event, el) {
            const ctx = context(el);

            if (! ctx || ! hasFiles(event)) {
                return;
            }

            event.preventDefault();
            uploadTo(ctx, Array.from(event.dataTransfer.files || []));
        },

        paste(event, el) {
            const ctx = context(el);

            if (! ctx || isTyping(event)) {
                return;
            }

            const files = Array.from((event.clipboardData && event.clipboardData.files) || []);

            if (! files.length) {
                return;
            }

            event.preventDefault();
            uploadTo(ctx, files);
        },
    };
})();

window.dispatchEvent(new CustomEvent('dashed-media-ready'));
