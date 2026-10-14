


<script>
    document.addEventListener('contextmenu', function (e) {
      e.preventDefault();
    });

    document.addEventListener('dragstart', function (e) {
      e.preventDefault();
    });

    document.addEventListener('keydown', function (e) {
      if (
        (e.ctrlKey || e.metaKey) &&
        ['c', 'x', 'u', 's', 'a'].includes(e.key.toLowerCase())
      ) {
        e.preventDefault();
      }

      if (e.key === 'F12') {
        e.preventDefault();
      }
    });
  </script>


 <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
 <script>
    AOS.init({
        once: true,
        offset: 80,
    });
</script>
<script>
    document.addEventListener('contextmenu', function (e) {
      e.preventDefault();
    });

    document.addEventListener('dragstart', function (e) {
      e.preventDefault();
    });

    document.addEventListener('keydown', function (e) {
      if (
        (e.ctrlKey || e.metaKey) &&
        ['c', 'x', 'u', 's', 'a'].includes(e.key.toLowerCase())
      ) {
        e.preventDefault();
      }

      if (e.key === 'F12') {
        e.preventDefault();
      }
    });
  </script>

  <script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'LocalBusiness',
    'name' => 'Velora Digital',
    'url' => url('/'),
    'logo' => asset('images/logo.png'),
    'telephone' => '+628123456789',
    'address' => [
        '@type' => 'PostalAddress',
        'addressLocality' => 'Purwokerto',
        'addressCountry' => 'ID',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}
</script>

  

  