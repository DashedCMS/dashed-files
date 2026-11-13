{{--
    De makelaar tussen een mediaveld en het keuzevenster. Een veld roept
    window.dashedMedia.pick() of .upload() aan met zijn sleutel en het
    Livewire-id van zijn pagina; de makelaar onthoudt die twee (geen
    DOM-element: een veld in een blok kan tussentijds opnieuw getekend zijn)
    en meldt de keuze terug via applySelection() op het veld.
--}}
@once
<script data-dashed-media-picker-script>
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
            host = { el, wire, id: el.dataset.dashedMediaPickerHost, open: false };
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
            window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: host.id } }));

            host.wire.openPicker({
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
            });

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
</script>
@endonce
