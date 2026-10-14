
<section class="relative bg-white py-12 sm:py-14">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <p class="text-center text-sm sm:text-base font-medium text-slate-500">
      Jenis website yang bisa kami buat —
    </p>

    <div class="mt-8 relative overflow-hidden">
      <!-- soft fade edges -->
      <div class="pointer-events-none absolute inset-y-0 left-0 w-20 bg-gradient-to-r from-white to-transparent z-10"></div>
      <div class="pointer-events-none absolute inset-y-0 right-0 w-20 bg-gradient-to-l from-white to-transparent z-10"></div>

      <!-- Track (animasi) -->
      <div class="marquee-track">
        <!-- group A -->
        <div class="marquee-group">
          <div class="stack-pill"><i class="fa-solid fa-building"></i><span>Website Perusahaan</span></div>
          <div class="stack-pill"><i class="fa-solid fa-bullhorn"></i><span>Landing Page</span></div>
          <div class="stack-pill"><i class="fa-solid fa-store"></i><span>Toko Online</span></div>
          <div class="stack-pill"><i class="fa-solid fa-utensils"></i><span>Restaurant</span></div>
          <div class="stack-pill"><i class="fa-solid fa-house"></i><span>Properti</span></div>
          <div class="stack-pill"><i class="fa-solid fa-gavel"></i><span>Legalitas</span></div>
          <div class="stack-pill"><i class="fa-solid fa-user-doctor"></i><span>Klinik</span></div>
          <div class="stack-pill"><i class="fa-solid fa-graduation-cap"></i><span>Sekolah/Kursus</span></div>
          <div class="stack-pill"><i class="fa-solid fa-hotel"></i><span>Hotel/Villa</span></div>
          <div class="stack-pill"><i class="fa-solid fa-calendar-check"></i><span>Booking System</span></div>
        </div>

        <!-- group B (duplikat) -->
        <div class="marquee-group" aria-hidden="true">
          <div class="stack-pill"><i class="fa-solid fa-building"></i><span>Website Perusahaan</span></div>
          <div class="stack-pill"><i class="fa-solid fa-bullhorn"></i><span>Landing Page</span></div>
          <div class="stack-pill"><i class="fa-solid fa-store"></i><span>Toko Online</span></div>
          <div class="stack-pill"><i class="fa-solid fa-utensils"></i><span>Restaurant</span></div>
          <div class="stack-pill"><i class="fa-solid fa-house"></i><span>Properti</span></div>
          <div class="stack-pill"><i class="fa-solid fa-gavel"></i><span>Legalitas</span></div>
          <div class="stack-pill"><i class="fa-solid fa-user-doctor"></i><span>Klinik</span></div>
          <div class="stack-pill"><i class="fa-solid fa-graduation-cap"></i><span>Sekolah/Kursus</span></div>
          <div class="stack-pill"><i class="fa-solid fa-hotel"></i><span>Hotel/Villa</span></div>
          <div class="stack-pill"><i class="fa-solid fa-calendar-check"></i><span>Booking System</span></div>
        </div>
      </div>
    </div>
  </div>

  <style>
    /* Track: 2 group sejajar, geser 50% biar loop mulus */
    .marquee-track {
      --duration: 28s;
      display: flex;
      width: max-content;
      gap: 1.25rem;
      will-change: transform;
      animation: marquee-scroll var(--duration) linear infinite;
    }

    .marquee-group {
      display: flex;
      align-items: center;
      gap: 1.25rem;
      width: max-content;      /* penting: jangan 100% */
      flex: 0 0 auto;          /* penting: jangan shrink */
    }

    /* Pause hover */
    .marquee-track:hover {
      animation-play-state: paused;
    }

    @keyframes marquee-scroll {
      from { transform: translateX(0); }
      to   { transform: translateX(-50%); } /* karena ada 2 group identik */
    }

    .stack-pill {
      display: inline-flex;
      align-items: center;
      gap: .55rem;
      padding: .6rem .9rem;
      border: 1px solid rgba(148,163,184,.35);
      border-radius: 9999px;
      background: rgba(255,255,255,.9);
      box-shadow: 0 10px 30px rgba(15,23,42,.06);
      color: rgba(51,65,85,.9);
      font-weight: 600;
      font-size: .95rem;
      white-space: nowrap;
      flex: 0 0 auto;          /* penting: jangan shrink */
    }

    .stack-pill i {
      font-size: 1.05rem;
      opacity: .9;
    }

    @media (max-width: 640px) {
      .marquee-track { --duration: 22s; }
      .stack-pill { padding: .5rem .75rem; font-size: .9rem; }
    }

    @media (prefers-reduced-motion: reduce) {
      .marquee-track { animation: none; transform: none; }
    }
  </style>
</section>