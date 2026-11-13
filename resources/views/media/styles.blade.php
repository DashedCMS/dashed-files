{{--
    Opmaak van de mediabibliotheek. Een eigen stijlblok met eigen klassen:
    klantprojecten compileren de views van dit pakket niet mee in Tailwind.
    Kleuren lopen via eigen variabelen op .dashed-media, met Filaments
    variabelen als bron en een vaste waarde als terugval.
--}}
@once
<style data-dashed-media-styles>
    .dashed-media {
        --dm-bg: #fff;
        --dm-soft: var(--gray-50, #f9fafb);
        --dm-hover: var(--gray-100, #f3f4f6);
        --dm-border: var(--gray-200, #e5e7eb);
        --dm-text: var(--gray-950, #09090b);
        --dm-muted: var(--gray-500, #6b7280);
        --dm-accent: var(--primary-600, #2563eb);
        --dm-accent-soft: var(--primary-50, #eff6ff);
        --dm-accent-text: var(--primary-700, #1d4ed8);
        --dm-danger: var(--danger-600, #dc2626);
        --dm-warning: var(--warning-600, #d97706);
        --dm-success: var(--success-600, #16a34a);
        --dm-check-a: #f3f4f6;
        --dm-check-b: #fff;
        position: relative;
        box-sizing: border-box;
        display: grid;
        grid-template-columns: 250px minmax(0, 1fr);
        height: calc(100vh - 11rem);
        height: var(--dm-height, calc(100dvh - 11rem));
        min-height: 460px;
        background: var(--dm-bg);
        color: var(--dm-text);
        border: 1px solid var(--dm-border);
        border-radius: 12px;
        overflow: hidden;
        font-size: 14px;
        line-height: 1.4;
    }
    .dark .dashed-media {
        --dm-bg: var(--gray-900, #18181b);
        --dm-soft: var(--gray-950, #09090b);
        --dm-hover: rgb(255 255 255 / .07);
        --dm-border: rgb(255 255 255 / .12);
        --dm-text: var(--gray-100, #f4f4f5);
        --dm-muted: var(--gray-400, #a1a1aa);
        --dm-accent: var(--primary-400, #60a5fa);
        --dm-accent-soft: rgb(255 255 255 / .09);
        --dm-accent-text: var(--primary-300, #93c5fd);
        --dm-danger: var(--danger-400, #f87171);
        --dm-warning: var(--warning-400, #fbbf24);
        --dm-success: var(--success-400, #4ade80);
        --dm-check-a: #3f3f46;
        --dm-check-b: #27272a;
    }
    .dashed-media--detail { grid-template-columns: 250px minmax(0, 1fr) 340px; }
    .dashed-media *, .dashed-media *::before, .dashed-media *::after { box-sizing: border-box; }
    .dashed-media [x-cloak] { display: none !important; }
    .dashed-media-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    .dashed-media-icon { width: 18px; height: 18px; flex: none; }
    .dashed-media-icon--lg { width: 40px; height: 40px; }
    .dashed-media :focus-visible { outline: 2px solid var(--dm-accent); outline-offset: 2px; }

    /* Knoppen en velden */
    .dashed-media-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border: 1px solid var(--dm-border); border-radius: 8px; background: var(--dm-bg); color: inherit; font: inherit; font-weight: 500; cursor: pointer; white-space: nowrap; text-decoration: none; }
    .dashed-media-btn:hover { background: var(--dm-hover); }
    .dashed-media-btn[disabled] { opacity: .5; cursor: default; }
    .dashed-media-btn--primary { background: var(--primary-600, #2563eb); border-color: transparent; color: #fff; }
    .dashed-media-btn--primary:hover { background: var(--primary-500, #3b82f6); }
    .dashed-media-btn--danger { color: var(--dm-danger); }
    .dashed-media-btn--danger-solid { background: var(--danger-600, #dc2626); border-color: transparent; color: #fff; }
    .dashed-media-btn--danger-solid:hover { background: var(--danger-500, #ef4444); }
    .dashed-media-btn--ghost { border-color: transparent; background: transparent; padding: 4px; }
    .dashed-media-btn--on { background: var(--dm-accent-soft); color: var(--dm-accent-text); border-color: var(--dm-accent); }
    .dashed-media-input, .dashed-media-select, .dashed-media-textarea { width: 100%; padding: 6px 9px; border: 1px solid var(--dm-border); border-radius: 8px; background: var(--dm-bg); color: inherit; font: inherit; }
    .dashed-media-textarea { resize: vertical; min-height: 62px; }
    .dashed-media-select { width: auto; max-width: 100%; }
    .dashed-media-label { display: block; margin: 0 0 4px; font-size: 12px; font-weight: 600; color: var(--dm-muted); }
    .dashed-media-badge { display: inline-flex; align-items: center; gap: 4px; padding: 1px 7px; border-radius: 999px; background: var(--dm-hover); font-size: 11px; font-weight: 600; }

    /* Boom */
    .dashed-media-side { display: flex; flex-direction: column; min-height: 0; border-right: 1px solid var(--dm-border); background: var(--dm-soft); }
    .dashed-media-side-scroll { flex: 1; overflow-y: auto; padding: 8px; }
    .dashed-media-side-head { display: flex; align-items: center; justify-content: space-between; padding: 12px 10px 4px; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--dm-muted); }
    .dashed-media-tree { list-style: none; margin: 0; padding: 0; }
    .dashed-media-node { position: relative; display: flex; align-items: center; gap: 4px; min-height: 32px; padding: 3px 6px; border: 1px solid transparent; border-radius: 8px; cursor: pointer; user-select: none; }
    .dashed-media-node:hover { background: var(--dm-hover); }
    .dashed-media-node[aria-current="true"] { background: var(--dm-accent-soft); color: var(--dm-accent-text); font-weight: 600; box-shadow: inset 3px 0 0 var(--dm-accent); }
    .dashed-media-node--drop { border: 1px dashed var(--dm-accent); background: var(--dm-accent-soft); }
    .dashed-media-node-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dashed-media-node-count { font-size: 12px; color: var(--dm-muted); font-weight: 400; }
    .dashed-media-twist { display: inline-flex; width: 20px; height: 20px; align-items: center; justify-content: center; border: 0; background: transparent; color: inherit; cursor: pointer; border-radius: 4px; padding: 0; flex: none; }
    .dashed-media-twist svg { width: 14px; height: 14px; transition: transform .12s; }
    .dashed-media-twist[aria-expanded="true"] svg { transform: rotate(90deg); }
    .dashed-media-twist--empty { visibility: hidden; }
    .dashed-media-node-menu-btn { opacity: 0; }
    .dashed-media-node:hover .dashed-media-node-menu-btn, .dashed-media-node:focus-within .dashed-media-node-menu-btn, .dashed-media-node-menu-btn[aria-expanded="true"] { opacity: 1; }
    .dashed-media-menu { position: absolute; z-index: 30; top: 100%; right: 4px; min-width: 190px; padding: 4px; background: var(--dm-bg); color: var(--dm-text); border: 1px solid var(--dm-border); border-radius: 10px; box-shadow: 0 10px 30px rgb(0 0 0 / .18); font-weight: 400; }
    .dashed-media-menu button { display: flex; width: 100%; align-items: center; gap: 8px; padding: 7px 9px; border: 0; border-radius: 6px; background: transparent; color: inherit; font: inherit; text-align: left; cursor: pointer; }
    .dashed-media-menu button:hover { background: var(--dm-hover); }

    /* Midden */
    .dashed-media-main { display: flex; flex-direction: column; min-width: 0; min-height: 0; }
    .dashed-media-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 10px 12px; border-bottom: 1px solid var(--dm-border); }
    .dashed-media-crumbs { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; flex: 1 1 100%; min-width: 0; font-weight: 600; }
    .dashed-media-crumbs button { border: 0; background: transparent; color: inherit; font: inherit; cursor: pointer; padding: 2px 4px; border-radius: 4px; }
    .dashed-media-crumbs button:hover { background: var(--dm-hover); }
    .dashed-media-crumbs span[aria-hidden] { color: var(--dm-muted); }
    .dashed-media-search { position: relative; flex: 1 1 200px; max-width: 320px; }
    .dashed-media-search svg { position: absolute; left: 9px; top: 50%; transform: translateY(-50%); color: var(--dm-muted); pointer-events: none; }
    .dashed-media-search input { padding-left: 32px; }
    .dashed-media-spacer { flex: 1; }
    .dashed-media-pop { position: relative; }
    .dashed-media-pop-panel { position: absolute; z-index: 30; top: calc(100% + 4px); left: 0; width: 260px; max-height: 320px; overflow-y: auto; padding: 8px; background: var(--dm-bg); border: 1px solid var(--dm-border); border-radius: 10px; box-shadow: 0 10px 30px rgb(0 0 0 / .18); }
    .dashed-media-pop-panel label { display: flex; align-items: center; gap: 8px; padding: 5px 4px; border-radius: 6px; cursor: pointer; }
    .dashed-media-pop-panel label:hover { background: var(--dm-hover); }
    .dashed-media-more { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; padding: 10px 12px; border-bottom: 1px solid var(--dm-border); background: var(--dm-soft); }
    .dashed-media-check { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
    .dashed-media-bulk { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 8px 12px; border-bottom: 1px solid var(--dm-border); background: var(--dm-accent-soft); }
    .dashed-media-bulk strong { margin-right: 4px; }
    .dashed-media-note { padding: 6px 12px; border-bottom: 1px solid var(--dm-border); font-size: 13px; color: var(--dm-muted); }

    /* Uploadlijst */
    /* flex: none, anders drukt de kolom de lijst samen en valt de onderste rij half weg. */
    .dashed-media-uploads { flex: none; max-height: 190px; overflow-y: auto; border-bottom: 1px solid var(--dm-border); background: var(--dm-soft); padding-bottom: 6px; }
    .dashed-media-uploads-head { position: sticky; top: 0; z-index: 1; display: flex; align-items: center; justify-content: space-between; padding: 6px 12px; font-weight: 600; font-size: 13px; background: var(--dm-soft); }
    /* De naam houdt altijd zijn eigen kolom; een lange foutreden breekt af in de zijne. */
    .dashed-media-upload { display: grid; grid-template-columns: minmax(140px, 1fr) 90px minmax(0, 1.4fr); align-items: start; gap: 4px 10px; padding: 4px 12px; font-size: 13px; }
    .dashed-media-upload-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
    .dashed-media-upload .dashed-media-progress { margin-top: 6px; }
    .dashed-media-upload-state { min-width: 0; overflow-wrap: anywhere; }
    .dashed-media-progress { height: 6px; border-radius: 999px; background: var(--dm-hover); overflow: hidden; }
    .dashed-media-progress span { display: block; height: 100%; background: var(--dm-accent); transition: width .15s; }
    .dashed-media-upload-state[data-status="done"] { color: var(--dm-success); }
    .dashed-media-upload-state[data-status="duplicate"] { color: var(--dm-warning); }
    .dashed-media-upload-state[data-status="error"] { color: var(--dm-danger); }

    /* Raster en lijst */
    .dashed-media-scroll { position: relative; flex: 1; min-height: 0; overflow-y: auto; padding: 12px; }
    .dashed-media-scroll--drop { outline: 2px dashed var(--dm-accent); outline-offset: -6px; background: var(--dm-accent-soft); }
    .dashed-media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; list-style: none; margin: 0; padding: 0; }
    .dashed-media-tile { position: relative; display: flex; flex-direction: column; border: 2px solid var(--dm-border); border-radius: 10px; background: var(--dm-bg); cursor: pointer; overflow: hidden; }
    .dashed-media-tile:hover { border-color: var(--dm-muted); }
    .dashed-media-tile[aria-selected="true"] { border-color: var(--dm-accent); box-shadow: 0 0 0 2px var(--dm-accent); }
    .dashed-media-thumb { position: relative; aspect-ratio: 4 / 3; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 4px; color: var(--dm-muted); background-color: var(--dm-soft); }
    /* Dambord alleen waar doorzichtigheid kan voorkomen (png, webp, gif, svg, avif). */
    .dashed-media-thumb--check { background-color: var(--dm-check-b);
        background-image: linear-gradient(45deg, var(--dm-check-a) 25%, transparent 25%, transparent 75%, var(--dm-check-a) 75%), linear-gradient(45deg, var(--dm-check-a) 25%, transparent 25%, transparent 75%, var(--dm-check-a) 75%);
        background-size: 16px 16px; background-position: 0 0, 8px 8px; }
    .dashed-media-thumb img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; }
    .dashed-media-ext { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
    .dashed-media-tile .dashed-media-name { padding: 6px 8px; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; border-top: 1px solid var(--dm-border); }
    .dashed-media-tick { position: absolute; z-index: 2; top: 6px; left: 6px; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; border: 2px solid var(--dm-border); border-radius: 6px; background: var(--dm-bg); color: transparent; cursor: pointer; padding: 0; opacity: 0; }
    .dashed-media-tick svg { width: 16px; height: 16px; }
    .dashed-media-tile:hover .dashed-media-tick, .dashed-media-row:hover .dashed-media-tick, .dashed-media-tick[aria-checked="true"], .dashed-media--touch .dashed-media-tick, .dashed-media--selecting .dashed-media-tick { opacity: 1; }
    .dashed-media-tick[aria-checked="true"] { background: var(--primary-600, #2563eb); border-color: var(--primary-600, #2563eb); color: #fff; }
    .dashed-media-star { position: absolute; z-index: 2; top: 6px; right: 6px; width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 999px; background: rgb(0 0 0 / .45); color: #fff; cursor: pointer; padding: 0; opacity: 0; }
    .dashed-media-star svg { width: 16px; height: 16px; }
    .dashed-media-tile:hover .dashed-media-star, .dashed-media-star[aria-pressed="true"], .dashed-media--touch .dashed-media-star { opacity: 1; }
    .dashed-media-star[aria-pressed="true"] { color: #fbbf24; }
    .dashed-media-flag { position: absolute; z-index: 2; bottom: 6px; left: 6px; display: inline-flex; align-items: center; gap: 3px; padding: 1px 6px; border-radius: 999px; background: var(--warning-500, #f59e0b); color: #111; font-size: 10px; font-weight: 700; }
    .dashed-media-flag svg { width: 12px; height: 12px; }
    /* Fase 9b: markering op de voorvertoning van een video of pdf, en de duur of het aantal pagina's. */
    /* Fase 10: privé, sites en bestanden die nog onderweg zijn. */
    .dashed-media-lock { display: inline-flex; align-items: center; flex: none; color: var(--dm-muted); }
    .dashed-media-lock svg { width: 12px; height: 12px; }
    .dashed-media-lock--tile { position: absolute; z-index: 2; top: 6px; right: 6px; justify-content: center; width: 22px; height: 22px; border-radius: 999px; background: rgba(0, 0, 0, .6); color: #fff; }
    .dashed-media-row .dashed-media-lock--tile { top: 2px; right: 2px; width: 14px; height: 14px; }
    .dashed-media-row .dashed-media-lock--tile svg { width: 9px; height: 9px; }
    .dashed-media-moving { flex: none; padding: 0 6px; border-radius: 999px; background: var(--dm-soft); border: 1px solid var(--dm-warning); font-size: 10px; white-space: nowrap; }
    .dashed-media-sitefilter { display: inline-flex; align-items: center; gap: 4px; padding: 0 4px; border-radius: 8px; border: 1px solid transparent; }
    .dashed-media-sitefilter--on { border-color: var(--dm-warning); background: var(--dm-soft); }
    .dashed-media-sites { display: flex; flex-direction: column; gap: 6px; margin: 0; padding: 0; border: 0; }
    .dashed-media-banner svg { width: 12px; height: 12px; display: inline; vertical-align: -1px; }
    .dashed-media-kind { position: absolute; z-index: 1; top: 50%; left: 50%; transform: translate(-50%, -50%); display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 999px; background: rgba(0, 0, 0, .55); color: #fff; pointer-events: none; }
    .dashed-media-kind svg { width: 18px; height: 18px; }
    .dashed-media-kind--pdf { top: 6px; left: 6px; transform: none; width: auto; height: auto; padding: 1px 6px; border-radius: 4px; background: #b91c1c; font-size: 10px; font-weight: 700; letter-spacing: .04em; }
    .dashed-media-length { position: absolute; z-index: 2; right: 6px; bottom: 6px; padding: 1px 6px; border-radius: 4px; background: rgba(0, 0, 0, .7); color: #fff; font-size: 10px; font-weight: 600; font-variant-numeric: tabular-nums; pointer-events: none; }
    .dashed-media-row .dashed-media-kind { width: 20px; height: 20px; }
    .dashed-media-row .dashed-media-kind svg { width: 11px; height: 11px; }
    .dashed-media-row .dashed-media-kind--pdf, .dashed-media-row .dashed-media-length { display: none; }
    .dashed-media-list { list-style: none; margin: 0; padding: 0; }
    .dashed-media-row { position: relative; display: grid; grid-template-columns: 30px 48px minmax(0, 1fr) 100px 110px 80px 110px; align-items: center; gap: 10px; padding: 5px 8px; border: 2px solid transparent; border-bottom: 1px solid var(--dm-border); cursor: pointer; }
    .dashed-media-row:hover { background: var(--dm-hover); }
    .dashed-media-row[aria-selected="true"] { border-color: var(--dm-accent); background: var(--dm-accent-soft); border-radius: 8px; }
    .dashed-media-row .dashed-media-tick { position: static; opacity: 1; }
    .dashed-media-row .dashed-media-thumb { width: 48px; aspect-ratio: 1; border-radius: 6px; overflow: hidden; }
    .dashed-media-row .dashed-media-thumb .dashed-media-icon--lg { width: 22px; height: 22px; }
    .dashed-media-row .dashed-media-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
    .dashed-media-row-meta { color: var(--dm-muted); font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .dashed-media-tile .dashed-media-row-meta, .dashed-media-row .dashed-media-star, .dashed-media-row .dashed-media-flag { display: none; }
    .dashed-media-list-head { position: sticky; top: -12px; z-index: 3; background: var(--dm-bg); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--dm-muted); cursor: default; }
    .dashed-media-list-head:hover { background: var(--dm-bg); }
    .dashed-media-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; min-height: 260px; text-align: center; color: var(--dm-muted); padding: 24px; }
    .dashed-media-empty strong { color: var(--dm-text); font-size: 16px; }
    .dashed-media-skeleton { aspect-ratio: 4 / 3.6; border-radius: 10px; background: var(--dm-hover); animation: dashed-media-pulse 1.2s ease-in-out infinite; }
    @keyframes dashed-media-pulse { 50% { opacity: .45; } }
    .dashed-media-sentinel { height: 1px; }
    .dashed-media-foot { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px; color: var(--dm-muted); font-size: 13px; }
    .dashed-media-spin { width: 16px; height: 16px; border: 2px solid var(--dm-border); border-top-color: var(--dm-accent); border-radius: 50%; animation: dashed-media-spin .7s linear infinite; }
    @keyframes dashed-media-spin { to { transform: rotate(360deg); } }

    /* Detailpaneel */
    .dashed-media-detail { display: flex; flex-direction: column; min-height: 0; border-left: 1px solid var(--dm-border); background: var(--dm-bg); }
    .dashed-media-detail-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; border-bottom: 1px solid var(--dm-border); font-weight: 600; }
    .dashed-media-detail-head span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dashed-media-detail-body { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 14px; }
    /* Zonder dit drukt de flex-kolom de voorvertoning (overflow: hidden) tot nul in. */
    .dashed-media-detail-body > * { flex: none; }
    .dashed-media-preview { border-radius: 10px; overflow: hidden; border: 1px solid var(--dm-border); }
    .dashed-media-preview .dashed-media-thumb { aspect-ratio: 4 / 3; }
    .dashed-media-preview video { display: block; width: 100%; max-height: 260px; background: #000; }
    .dashed-media-field-head { display: flex; align-items: center; justify-content: space-between; }
    .dashed-media-saved { display: inline-flex; align-items: center; gap: 3px; font-size: 12px; font-weight: 600; color: var(--dm-success); }
    .dashed-media-saved svg { width: 14px; height: 14px; }
    .dashed-media-locales { display: flex; flex-wrap: wrap; gap: 4px; }
    .dashed-media-locale { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border: 1px solid var(--dm-border); border-radius: 999px; background: var(--dm-bg); color: var(--dm-muted); font-size: 12px; font-weight: 600; cursor: pointer; }
    .dashed-media-locale:hover { background: var(--dm-hover); }
    .dashed-media-locale[aria-pressed="true"] { border-color: var(--dm-accent); color: var(--dm-accent); }
    .dashed-media-locale-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--dm-warning); }
    .dashed-media-locale-dot--on { background: var(--dm-success); }
    .dashed-media-ai-failed { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 6px; margin: 6px 0 0; font-size: 12px; color: var(--dm-warning); }
    .dashed-media-ai-failed > span { display: inline-flex; align-items: center; gap: 4px; }
    .dashed-media-ai-failed svg { width: 14px; height: 14px; }
    .dashed-media-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
    .dashed-media-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 4px 2px 9px; border-radius: 999px; background: var(--dm-hover); font-size: 12px; }
    .dashed-media-chip button { display: inline-flex; border: 0; background: transparent; color: inherit; cursor: pointer; padding: 2px; border-radius: 999px; }
    .dashed-media-chip button svg { width: 12px; height: 12px; }
    .dashed-media-suggest { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
    .dashed-media-suggest button { padding: 2px 8px; border: 1px dashed var(--dm-border); border-radius: 999px; background: transparent; color: inherit; font: inherit; font-size: 12px; cursor: pointer; }
    .dashed-media-facts { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 4px 12px; margin: 0; font-size: 13px; }
    .dashed-media-facts dt { color: var(--dm-muted); }
    .dashed-media-facts dd { margin: 0; overflow-wrap: anywhere; }
    .dashed-media-facts button { border: 0; background: transparent; color: var(--dm-accent-text); font: inherit; cursor: pointer; padding: 0; text-decoration: underline; }
    .dashed-media-actions { display: flex; flex-wrap: wrap; gap: 6px; }

    /* Dialoog */
    .dashed-media-backdrop { position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgb(0 0 0 / .5); }
    .dashed-media-dialog { width: 100%; max-width: 440px; max-height: 86vh; display: flex; flex-direction: column; background: var(--dm-bg); color: var(--dm-text); border: 1px solid var(--dm-border); border-radius: 14px; box-shadow: 0 20px 50px rgb(0 0 0 / .3); }
    .dashed-media-dialog-head { padding: 14px 16px 0; font-size: 16px; font-weight: 700; }
    .dashed-media-dialog-body { padding: 10px 16px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; }
    .dashed-media-dialog-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 10px 16px 14px; }
    .dashed-media-picklist { list-style: none; margin: 0; padding: 0; border: 1px solid var(--dm-border); border-radius: 8px; max-height: 300px; overflow-y: auto; }
    .dashed-media-picklist button { display: flex; width: 100%; align-items: center; gap: 6px; padding: 7px 9px; border: 0; background: transparent; color: inherit; font: inherit; text-align: left; cursor: pointer; }
    .dashed-media-picklist button:hover { background: var(--dm-hover); }
    .dashed-media-picklist button[aria-checked="true"] { background: var(--dm-accent-soft); color: var(--dm-accent-text); font-weight: 600; }
    .dashed-media-picklist button[disabled] { opacity: .45; cursor: default; }
    .dashed-media-only-narrow { display: none; }

    /* Keuzevenster */
    .dashed-media--pick { height: min(74vh, 780px); min-height: 0; }
    .dashed-media-picktabs { flex: none; display: flex; gap: 2px; padding: 8px 12px 0; border-bottom: 1px solid var(--dm-border); overflow-x: auto; }
    .dashed-media-picktab { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: var(--dm-muted); font: inherit; font-weight: 600; cursor: pointer; white-space: nowrap; }
    .dashed-media-picktab:hover { color: var(--dm-text); }
    .dashed-media-picktab[aria-selected="true"] { color: var(--dm-accent-text); border-bottom-color: var(--dm-accent); }
    .dashed-media-pickdrop { flex: 1; min-height: 240px; margin: 12px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; padding: 24px; text-align: center; color: var(--dm-muted); border: 2px dashed var(--dm-border); border-radius: 12px; }
    .dashed-media-pickdrop strong { color: var(--dm-text); font-size: 16px; }
    .dashed-media-pickdrop--over { border-color: var(--dm-accent); background: var(--dm-accent-soft); }
    .dashed-media-pickbar { flex: none; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 10px 12px; border-top: 1px solid var(--dm-border); background: var(--dm-soft); }
    .dashed-media-chosen { position: absolute; z-index: 2; top: 6px; left: 6px; display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 999px; background: var(--dm-accent); color: #fff; font-size: 10px; font-weight: 700; }
    .dashed-media-chosen .dashed-media-icon { width: 12px; height: 12px; }
    .dashed-media-item--chosen { opacity: .55; cursor: default; }
    .dashed-media-item--chosen .dashed-media-tick { display: none; }
    .dashed-media-picker-wait { display: flex; align-items: center; justify-content: center; gap: 10px; min-height: 240px; color: var(--gray-500, #6b7280); font-size: 14px; }

    @media (max-width: 1100px) {
        .dashed-media--detail { grid-template-columns: 220px minmax(0, 1fr) 300px; }
        .dashed-media { grid-template-columns: 220px minmax(0, 1fr); }
    }
    /* Tussen gestapeld en ruim: het detailpaneel ligt over het raster in plaats van er een derde kolom naast te zijn, anders blijft er voor het raster één smalle kolom over. */
    @media (min-width: 901px) and (max-width: 1400px) {
        .dashed-media--detail { grid-template-columns: 250px minmax(0, 1fr); }
        .dashed-media-detail { position: absolute; z-index: 20; top: 0; right: 0; bottom: 0; width: min(360px, 100%); box-shadow: -10px 0 30px rgb(0 0 0 / .2); }
    }
    @media (min-width: 901px) and (max-width: 1100px) {
        .dashed-media--detail { grid-template-columns: 220px minmax(0, 1fr); }
    }
    @media (max-width: 900px) {
        .dashed-media--pick { height: auto; }
        .dashed-media-pickbar { position: sticky; bottom: 0; z-index: 4; }
        /* Gestapeld: geen vaste hoogte, de pagina zelf scrolt. */
        .dashed-media, .dashed-media--detail { grid-template-columns: minmax(0, 1fr); height: auto; min-height: 320px; overflow: visible; }
        .dashed-media-scroll { overflow: visible; }
        .dashed-media-toolbar { border-radius: 12px 12px 0 0; }
        .dashed-media-only-narrow { display: inline-flex; }
        .dashed-media-side { display: none; position: fixed; z-index: 45; inset: 0 auto 0 0; width: min(300px, 86%); border-right: 1px solid var(--dm-border); box-shadow: 10px 0 30px rgb(0 0 0 / .2); }
        .dashed-media--tree .dashed-media-side { display: flex; }
        .dashed-media-detail { position: fixed; z-index: 45; inset: 0 0 0 auto; width: min(380px, 100%); box-shadow: -10px 0 30px rgb(0 0 0 / .2); }
        .dashed-media-node-menu-btn { opacity: 1; }
        .dashed-media-row { grid-template-columns: 30px 48px minmax(0, 1fr) 80px; }
        .dashed-media-row > .dashed-media-hide-narrow { display: none !important; }
    }
    @media (max-width: 640px) {
        .dashed-media-grid { grid-template-columns: minmax(0, 1fr); }
        .dashed-media-detail { width: 100%; }
        .dashed-media-search { max-width: none; flex-basis: 100%; }
        .dashed-media-upload { grid-template-columns: minmax(0, 1fr); }
        .dashed-media-upload .dashed-media-progress { display: none; }
    }
    /* Fase 6: waar gebruikt, prullenbak, vervangen, dubbel. */
    .dashed-media-flag--unused { left: auto; right: 6px; background: var(--gray-700, #3f3f46); color: #fff; }
    .dashed-media-flag--inuse { left: auto; right: 6px; background: var(--danger-600, #dc2626); color: #fff; }
    .dashed-media-caveat { margin: 0 0 10px; padding: 8px 10px; border: 1px solid var(--dm-border); border-radius: 8px; background: var(--dm-soft); color: var(--dm-muted); font-size: 12px; }
    .dashed-media-dialog-body .dashed-media-caveat { margin: 0; }
    .dashed-media-tree--trash { margin-top: 10px; padding-top: 8px; border-top: 1px solid var(--dm-border); }
    .dashed-media-banner { display: flex; flex-direction: column; gap: 4px; padding: 10px; border: 1px solid var(--dm-warning); border-radius: 8px; background: var(--dm-soft); font-size: 12px; }
    .dashed-media-banner .dashed-media-actions { margin-top: 4px; }
    .dashed-media-usage ul, .dashed-media-dialog-lines { list-style: none; margin: 6px 0 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
    .dashed-media-usage li { display: flex; flex-wrap: wrap; gap: 4px 8px; align-items: baseline; padding: 6px 8px; border: 1px solid var(--dm-border); border-radius: 8px; overflow-wrap: anywhere; }
    .dashed-media-usage a { color: var(--dm-accent-text); text-decoration: underline; }
    .dashed-media-dialog-lines { max-height: 180px; overflow: auto; font-size: 12px; color: var(--dm-muted); }
    .dashed-media-dialog-lines li { padding: 3px 0; border-bottom: 1px solid var(--dm-border); overflow-wrap: anywhere; }
    .dashed-media-trashfolders { list-style: none; margin: 0 0 10px; padding: 0; display: flex; flex-direction: column; gap: 4px; }
    .dashed-media-trashfolders li { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border: 1px solid var(--dm-border); border-radius: 8px; background: var(--dm-soft); }
    .dashed-media-trashfolders .dashed-media-row-meta { margin-left: auto; }
    .dashed-media-upload-again { grid-column: 1 / -1; justify-self: start; padding: 2px 6px; text-decoration: underline; }
    /* Fase 7: de pomp, het focuspunt en de bewerk-dialoog. */
    .dashed-media-run { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 6px 12px; border-bottom: 1px solid var(--dm-border); background: var(--dm-soft); font-size: 12px; }
    .dashed-media-run progress { width: 160px; max-width: 30vw; height: 8px; accent-color: var(--dm-accent); }
    .dashed-media-focal { display: flex; flex-direction: column; gap: 8px; }
    .dashed-media-focal-stage { position: relative; align-self: center; display: inline-block; line-height: 0; border-radius: 8px; overflow: hidden; border: 1px solid var(--dm-border); cursor: crosshair; touch-action: none; user-select: none; }
    .dashed-media-focal-stage img { display: block; max-width: 100%; max-height: 240px; pointer-events: none; }
    .dashed-media-focal-dot { position: absolute; width: 22px; height: 22px; margin: -11px 0 0 -11px; border-radius: 50%; border: 2px solid #fff; background: var(--dm-accent); box-shadow: 0 0 0 1px rgb(0 0 0 / .6), 0 2px 6px rgb(0 0 0 / .4); pointer-events: none; }
    .dashed-media-focal-previews { display: flex; gap: 8px; align-items: flex-end; }
    .dashed-media-focal-previews figure { margin: 0; display: flex; flex-direction: column; gap: 3px; font-size: 11px; color: var(--dm-muted); }
    .dashed-media-focal-previews img { display: block; object-fit: cover; border-radius: 6px; border: 1px solid var(--dm-border); background: var(--dm-soft); }
    .dashed-media-focal-square { width: 72px; height: 72px; }
    .dashed-media-focal-wide { width: 128px; height: 72px; }
    .dashed-media-dialog--wide { max-width: 760px; }
    .dashed-media-edit-wrap { display: flex; justify-content: center; background: var(--dm-soft); border: 1px solid var(--dm-border); border-radius: 8px; padding: 8px; }
    .dashed-media-edit-stage { position: relative; display: inline-block; line-height: 0; overflow: hidden; touch-action: none; user-select: none; }
    .dashed-media-edit-stage canvas { display: block; max-width: 100%; }
    .dashed-media-edit-crop { position: absolute; box-sizing: border-box; border: 2px solid #fff; box-shadow: 0 0 0 9999px rgb(0 0 0 / .55); cursor: move; }
    .dashed-media-edit-handle { position: absolute; width: 16px; height: 16px; margin: -8px 0 0 -8px; border-radius: 50%; background: #fff; border: 2px solid var(--dm-accent); }
    .dashed-media--touch .dashed-media-edit-handle { width: 26px; height: 26px; margin: -13px 0 0 -13px; }
    /* Fase 11: het paneel van een externe bron en de herkomst in het detailpaneel. */
    .dashed-media-source { display: flex; flex-direction: column; gap: 8px; padding: 12px; border-bottom: 1px solid var(--dm-border); background: var(--dm-soft); max-height: 70vh; overflow-y: auto; }
    .dashed-media--pick .dashed-media-source { flex: 1; max-height: none; border-bottom: 0; background: transparent; }
    .dashed-media-source-head { display: flex; align-items: center; gap: 8px; font-size: 14px; }
    .dashed-media-source-help { margin: 0; font-size: 12px; color: var(--dm-muted); }
    .dashed-media-source-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .dashed-media-source-row .dashed-media-input { flex: 1 1 240px; min-width: 0; }
    .dashed-media-source-row .dashed-media-source-select { flex: 0 0 auto; width: auto; }
    .dashed-media-source-form { display: flex; flex-direction: column; gap: 8px; }
    .dashed-media-source-reference { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 12px; color: var(--dm-muted); }
    .dashed-media-source-credit { margin: 6px 0; font-size: 12px; color: var(--dm-muted); }
    .dashed-media-source-credit a, .dashed-media-source-card a, .dashed-media-origin a { color: var(--dm-accent); text-decoration: underline; }
    .dashed-media-source-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; margin-bottom: 10px; }
    .dashed-media-source-card { margin: 0; display: flex; flex-direction: column; gap: 6px; padding: 6px; border: 1px solid var(--dm-border); border-radius: 8px; background: var(--dm-bg, transparent); }
    .dashed-media-source-card img { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 6px; background: var(--dm-soft); }
    .dashed-media-source-card figcaption { display: flex; flex-direction: column; gap: 2px; font-size: 11px; color: var(--dm-muted); overflow-wrap: anywhere; }
    .dashed-media-source-status { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 13px; }
    .dashed-media-source-status[data-status="error"] { color: var(--dm-danger, #b91c1c); }
    .dashed-media-source-status[data-status="done"] { color: var(--dm-success, #15803d); }
    .dashed-media-origin { display: flex; flex-direction: column; gap: 4px; font-size: 12px; }
    .dashed-media-origin-text { color: var(--dm-muted); overflow-wrap: anywhere; }
    .dashed-media-origin-credit { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }

    @media (prefers-reduced-motion: reduce) {
        .dashed-media *, .dashed-media *::before, .dashed-media *::after { animation: none !important; transition: none !important; }
    }
</style>
@endonce
