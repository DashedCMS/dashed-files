{{--
    Via de render hook BODY_END, op elke paneelpagina van de eigen motor:
    de verwijzingen naar stijl en script van het mediaveld en het
    keuzevenster, de svg-sprite met de iconen van het veld, en het venster
    zelf. Stijl en script zijn twee statische bestanden met de hash van hun
    inhoud in de URL (media.assets): de browser haalt ze één keer op. Ze
    staan hier, buiten elk Livewire-component, zodat geen render ze raakt.

    Het script staat zonder defer aan het eind van de body: zo draait het
    vóór de start van Alpine, net als het inline blok van eerder, en
    registreert het zijn Alpine-component op tijd.

    Heeft de pagina de bibliotheek nodig (de beheerpagina of een veld, zie
    MediaAssets::markNeeded()), dan staan ze er meteen. Anders staat er een
    klein stukje script dat ze pas ophaalt als er alsnog een veld in de
    pagina komt (een blok, een modal), en dat bij wire:navigate opnieuw
    wordt uitgevoerd zonder iets dubbel te laden.
--}}
@if (\Dashed\DashedFiles\Classes\MediaAssets::needed())
    <link rel="stylesheet" href="{{ \Dashed\DashedFiles\Classes\MediaAssets::url('css') }}" data-dashed-media-css>
    <script src="{{ \Dashed\DashedFiles\Classes\MediaAssets::url('js') }}" data-dashed-media-script data-navigate-once></script>
@else
    <script data-dashed-media-loader>
        (() => {
            const css = @js(\Dashed\DashedFiles\Classes\MediaAssets::url('css'));
            const js = @js(\Dashed\DashedFiles\Classes\MediaAssets::url('js'));
            const sel = '[data-dashed-media-field],[data-dashed-media]';
            let queued = false;

            const load = () => {
                if (! document.querySelector('link[data-dashed-media-css]')) {
                    const link = document.createElement('link');
                    link.rel = 'stylesheet';
                    link.href = css;
                    link.setAttribute('data-dashed-media-css', '');
                    document.body.appendChild(link);
                }

                if (! window.dashedMedia && ! window.__dashedMediaLoading) {
                    window.__dashedMediaLoading = true;
                    const script = document.createElement('script');
                    script.src = js;
                    script.setAttribute('data-dashed-media-script', '');
                    document.body.appendChild(script);
                }
            };

            const check = () => {
                queued = false;

                if (document.querySelector(sel)) {
                    load();
                }
            };

            if (window.__dashedMediaObserver) {
                window.__dashedMediaObserver.disconnect();
            }

            check();
            window.__dashedMediaObserver = new MutationObserver(() => {
                if (! queued) {
                    queued = true;
                    requestAnimationFrame(check);
                }
            });
            window.__dashedMediaObserver.observe(document.documentElement, { childList: true, subtree: true });
        })();
    </script>
@endif

{!! \Dashed\DashedFiles\Classes\MediaIcons::sprite() !!}

@livewire(\Dashed\DashedFiles\Livewire\MediaPickerModal::COMPONENT, [], key('dashed-media-picker-modal'))
