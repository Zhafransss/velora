<!DOCTYPE html>
<html lang="id">

<head>
    <!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-MP5HVZ44');</script>
<!-- End Google Tag Manager -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Jasa Pembuatan Website Profesional di Purwokerto') | Velora Digital</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap">
</noscript>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <meta name="description" content="@yield('description', 'Velora Digital menyediakan jasa pembuatan website profesional untuk UMKM, bisnis, dan company profile di Purwokerto.')">

    <meta name="keywords"
        content="jasa pembuatan website purwokerto, jasa website profesional, jasa website murah, jasa website umkm">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:title" content="@yield('title')" />
    <meta property="og:description" content="@yield('description')" />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="{{ url()->current() }}" />
    <meta property="og:image" content="{{ asset('images/og-image.jpg') }}" />
<meta name="google-site-verification" content="MnXzAwMppS7sn8R9u5qkB3-RU0ALSdv2m-1l1KiSQRE" />
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('title', 'Velora Digital')">
    <meta name="twitter:description" content="@yield('description', 'Jasa pembuatan website profesional di Purwokerto.')">
    <meta name="twitter:image" content="{{ asset('images/og-image.jpg') }}">

    <script async src="https://www.googletagmanager.com/gtag/js?id=G-LLZ1VLMTLZ"></script> {{-- ini perlu ditambagkan google manager --}}
    <script>
        window.dataLayer = window.dataLayer || [];

        function gtag() {
            dataLayer.push(arguments);
        }
        gtag('js', new Date());
        gtag('config', 'G-LLZ1VLMTLZ');
    </script>

    <style>
        body {
            font-family: 'Poppins', sans-serif;
          
          
           
        }

        [x-cloak] {
            display: none !important;
        }
    </style>
    <head>

    <style>
        summary {
            list-style: none;
        }

        summary::-webkit-details-marker {
            display: none;
        }

        details > summary::marker {
            display: none;
            content: "";
        }
    </style>
</head>

<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=AW-18059024743">
</script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'AW-18059024743');
</script>
</head>

<body class="min-h-screen flex flex-col">
    <!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-MP5HVZ44"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->

    <x-partials.navbar />

    <main class="flex-1">
        @yield('content')
    </main>

    <x-partials.footer />
    <x-scripts.script />
    <script src="https://kit.fontawesome.com/924f062685.js" crossorigin="anonymous"></script>


</body>

</html>
