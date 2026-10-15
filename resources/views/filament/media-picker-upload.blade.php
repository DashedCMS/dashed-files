{{--
    Browserkant van UploadableMediaPicker: slepen op het veld of de knop
    "Uploaden" stuurt de bestanden via $wire.upload() naar
    componentFileAttachments.<statePath>.<sleutel>, en roept daarna
    attachUploadedFiles() op het veld aan. Eigen stijlblok, want niet elk
    klantproject compileert de views uit vendor/dashed mee in Tailwind.
--}}
<style>
    .dashed-media-picker-dropping {
        outline: 2px dashed var(--primary-500, #6366f1);
        outline-offset: 8px;
        border-radius: .5rem;
    }
    .dashed-media-picker-uploading {
        opacity: .7;
        pointer-events: none;
    }
    .dashed-media-picker-status {
        margin-top: .5rem;
        font-size: .75rem;
        color: var(--gray-500, #71717a);
    }
</style>
<script>
    window.dashedMediaPicker = (() => {
        const config = (el) => {
            try {
                return JSON.parse(el.dataset.dashedMediaPicker || '{}');
            } catch (e) {
                return {};
            }
        };

        const accepts = (file, accept) => {
            if (! accept || ! accept.length) {
                return true;
            }

            return accept.some((type) => type.endsWith('/*')
                ? (file.type || '').startsWith(type.slice(0, -1))
                : file.type === type);
        };

        const notify = (title, body, status) => {
            if (! window.FilamentNotification) {
                return;
            }

            const notification = new window.FilamentNotification().title(title);

            if (body) {
                notification.body(body);
            }

            notification[status]().send();
        };

        const setStatus = (el, text) => {
            let status = el.querySelector(':scope > .dashed-media-picker-status');

            if (! text) {
                status?.remove();

                return;
            }

            if (! status) {
                status = document.createElement('p');
                status.className = 'dashed-media-picker-status';
                el.appendChild(status);
            }

            status.textContent = text;
        };

        const hasFiles = (event) => Array.from(event.dataTransfer?.types || []).includes('Files');

        const upload = (el, $wire, files) => {
            const c = config(el);

            if (! c.key || ! files.length) {
                return;
            }

            const accepted = files.filter((file) => accepts(file, c.accept));

            if (accepted.length < files.length) {
                notify(c.labels.notAccepted, files.filter((file) => ! accepts(file, c.accept)).map((f) => f.name).join(', '), 'danger');
            }

            if (! accepted.length) {
                return;
            }

            if (! c.multiple && accepted.length > 1) {
                notify(c.labels.onlyOne, null, 'warning');
            }

            const list = c.multiple ? accepted : accepted.slice(0, 1);
            const keys = [];
            const progress = {};
            let done = 0;

            el.classList.add('dashed-media-picker-uploading');
            setStatus(el, c.labels.uploading.replace(':percent', '0'));

            const finish = () => {
                done++;

                if (done < list.length) {
                    return;
                }

                el.classList.remove('dashed-media-picker-uploading');
                setStatus(el, null);

                if (keys.length) {
                    $wire.callSchemaComponentMethod(c.key, 'attachUploadedFiles', [keys]);
                }
            };

            list.forEach((file, index) => {
                const fileKey = 'u' + Date.now().toString(36) + index + Math.random().toString(36).slice(2, 7);

                $wire.upload(
                    `componentFileAttachments.${c.statePath}.${fileKey}`,
                    file,
                    () => {
                        keys.push(fileKey);
                        finish();
                    },
                    () => {
                        notify(c.labels.failed, file.name, 'danger');
                        finish();
                    },
                    (event) => {
                        progress[index] = event.detail?.progress || 0;
                        const total = Object.values(progress).reduce((a, b) => a + b, 0) / list.length;
                        setStatus(el, c.labels.uploading.replace(':percent', String(Math.round(total))));
                    },
                );
            });
        };

        return {
            dragOver(event, el) {
                if (! hasFiles(event)) {
                    return;
                }

                el.classList.add('dashed-media-picker-dropping');
            },

            dragLeave(event, el) {
                if (event.relatedTarget && el.contains(event.relatedTarget)) {
                    return;
                }

                el.classList.remove('dashed-media-picker-dropping');
            },

            drop(event, el, $wire) {
                el.classList.remove('dashed-media-picker-dropping');

                if (! hasFiles(event)) {
                    return;
                }

                upload(el, $wire, Array.from(event.dataTransfer.files || []));
            },

            pick(event, el, $wire) {
                const c = config(el);

                if (! c.id || event.detail?.id !== c.id) {
                    return;
                }

                const input = document.createElement('input');
                input.type = 'file';
                input.multiple = !! c.multiple;

                if (c.accept && c.accept.length) {
                    input.accept = c.accept.join(',');
                }

                input.addEventListener('change', () => upload(el, $wire, Array.from(input.files || [])));
                input.click();
            },
        };
    })();
</script>
