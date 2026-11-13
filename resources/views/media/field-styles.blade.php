{{--
    Opmaak van het mediaveld (MediaField). Eigen klassen en een eigen
    stijlblok: klantprojecten compileren de views van dit pakket niet mee.
--}}
@once
<style data-dashed-media-field-styles>
    .dashed-media-field {
        --dmf-bg: #fff;
        --dmf-soft: var(--gray-50, #f9fafb);
        --dmf-border: var(--gray-200, #e5e7eb);
        --dmf-text: var(--gray-950, #09090b);
        --dmf-muted: var(--gray-500, #6b7280);
        --dmf-accent: var(--primary-600, #2563eb);
        --dmf-accent-soft: var(--primary-50, #eff6ff);
        --dmf-danger: var(--danger-600, #dc2626);
        position: relative;
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 10px;
        border: 1px dashed var(--dmf-border);
        border-radius: 10px;
        background: var(--dmf-bg);
        color: var(--dmf-text);
        font-size: 14px;
        line-height: 1.4;
    }
    .dark .dashed-media-field {
        --dmf-bg: rgb(255 255 255 / .04);
        --dmf-soft: rgb(255 255 255 / .06);
        --dmf-border: rgb(255 255 255 / .16);
        --dmf-text: var(--gray-100, #f4f4f5);
        --dmf-muted: var(--gray-400, #a1a1aa);
        --dmf-accent: var(--primary-400, #60a5fa);
        --dmf-accent-soft: rgb(255 255 255 / .09);
        --dmf-danger: var(--danger-400, #f87171);
    }
    .dashed-media-field *, .dashed-media-field *::before, .dashed-media-field *::after { box-sizing: border-box; }
    .dashed-media-field [x-cloak] { display: none !important; }
    .dashed-media-field--over { border-color: var(--dmf-accent); background: var(--dmf-accent-soft); }
    .dashed-media-field--busy { opacity: .75; }
    .dashed-media-field--disabled { border-style: solid; background: var(--dmf-soft); }
    .dashed-media-field:focus-visible, .dashed-media-field :focus-visible { outline: 2px solid var(--dmf-accent); outline-offset: 2px; }
    .dashed-media-field-icon { width: 16px; height: 16px; flex: none; }
    .dashed-media-field-icon--lg { width: 28px; height: 28px; }
    .dashed-media-field-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 8px; margin: 0; padding: 0; list-style: none; }
    .dashed-media-field-item { display: flex; gap: 10px; min-width: 0; padding: 8px; border: 1px solid var(--dmf-border); border-radius: 8px; background: var(--dmf-bg); }
    .dashed-media-field-item[draggable="true"] { cursor: grab; }
    .dashed-media-field-item--missing { background: var(--dmf-soft); color: var(--dmf-muted); }
    .dashed-media-field-thumb { position: relative; flex: none; width: 72px; height: 72px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; border-radius: 6px; background: var(--dmf-soft); color: var(--dmf-muted); overflow: hidden; }
    .dashed-media-field-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    /* Fase 9b: markering op de voorvertoning van een video of pdf. */
    .dashed-media-field-kind { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 999px; background: rgba(0, 0, 0, .55); color: #fff; pointer-events: none; }
    .dashed-media-field-kind svg { width: 12px; height: 12px; }
    .dashed-media-field-kind--pdf { top: 4px; left: 4px; transform: none; width: auto; height: auto; padding: 0 4px; border-radius: 3px; background: #b91c1c; font-size: 9px; font-weight: 700; }
    .dashed-media-field-ext { font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .dashed-media-field-flag { position: absolute; left: 3px; bottom: 3px; padding: 0 5px; border-radius: 999px; background: var(--warning-500, #f59e0b); color: #111; font-size: 9px; font-weight: 700; }
    /* Fase 10: een bestand uit een privé-map. */
    .dashed-media-field-lock { position: absolute; top: 3px; right: 3px; display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 999px; background: rgba(0, 0, 0, .6); color: #fff; }
    .dashed-media-field-lock svg { width: 11px; height: 11px; }
    .dashed-media-field-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
    .dashed-media-field-name { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dashed-media-field-meta { font-size: 12px; color: var(--dmf-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dashed-media-field-actions { display: flex; flex-wrap: wrap; gap: 2px; margin-top: auto; }
    .dashed-media-field-btn { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border: 1px solid var(--dmf-border); border-radius: 8px; background: var(--dmf-bg); color: inherit; font: inherit; font-weight: 500; cursor: pointer; text-decoration: none; white-space: nowrap; }
    .dashed-media-field-btn:hover { background: var(--dmf-soft); }
    .dashed-media-field-btn[disabled] { opacity: .4; cursor: default; }
    .dashed-media-field-btn--primary { background: var(--primary-600, #2563eb); border-color: transparent; color: #fff; }
    .dashed-media-field-btn--primary:hover { background: var(--primary-500, #3b82f6); }
    .dashed-media-field-btn--icon { padding: 4px; border-color: transparent; background: transparent; color: var(--dmf-muted); }
    .dashed-media-field-btn--icon:hover { color: var(--dmf-text); }
    .dashed-media-field-btn--danger:hover { color: var(--dmf-danger); }
    .dashed-media-field-flag--trash { background: var(--gray-700, #3f3f46); color: #fff; }
    .dashed-media-field-alt { display: flex; gap: 4px; }
    .dashed-media-field-locale { align-self: center; padding: 1px 6px; border: 1px solid var(--dmf-border); border-radius: 999px; font-size: 10px; font-weight: 700; }
    .dashed-media-field-alt input { flex: 1; min-width: 0; padding: 4px 7px; border: 1px solid var(--dmf-border); border-radius: 6px; background: var(--dmf-bg); color: inherit; font: inherit; font-size: 13px; }
    .dashed-media-field-empty { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; color: var(--dmf-muted); font-size: 13px; }
    .dashed-media-field-foot { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; color: var(--dmf-muted); font-size: 13px; }
    .dashed-media-field-status { margin: 0; font-size: 12px; color: var(--dmf-muted); }
    .dashed-media-field-status:empty { display: none; }
    .dashed-media-field-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    @media (max-width: 640px) {
        .dashed-media-field-list { grid-template-columns: minmax(0, 1fr); }
    }
</style>
@endonce
