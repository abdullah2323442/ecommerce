@php($tags = app(\App\Services\Analytics\Tags::class)->forRequest(request()))
@if ($tags && $tags['meta_pixel'])
    <script>
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', @json($tags['meta_pixel']));
    </script>
@endif
@if ($tags && $tags['ga4'])
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $tags['ga4'] }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', @json($tags['ga4']), { send_page_view: false });
    </script>
@endif
