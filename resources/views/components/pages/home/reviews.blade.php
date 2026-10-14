
<section id="reviews" class="relative overflow-hidden bg-white py-16 sm:py-20"
  data-aos="fade-up" data-aos-duration="1000">
  <!-- soft glow -->
  <div class="pointer-events-none absolute inset-0">
    <div
      class="absolute -top-40 left-1/2 h-[520px] w-[520px] -translate-x-1/2 rounded-full blur-3xl
             bg-gradient-to-tr from-[#20B7FF]/12 via-[#696FC7]/10 to-[#7C3AED]/10">
    </div>
  </div>

  <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="grid gap-12 lg:gap-10 lg:grid-cols-12 lg:items-center">

      <!-- LEFT -->
      <div class="lg:col-span-5 text-center lg:text-left" data-aos="fade-right" data-aos-delay="100">
        <div
          class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white/70 backdrop-blur px-4 py-2 text-sm font-semibold text-slate-700
                 shadow-[0_10px_30px_rgba(15,23,42,0.06)]"
          data-aos="fade-up" data-aos-delay="150">
          <span
            class="inline-block h-2 w-2 rounded-full bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]"></span>
          Testimoni Klien
        </div>

        <h2 class="mt-5 text-2xl sm:text-3xl lg:text-4xl font-semibold tracking-tight text-slate-900 leading-snug lg:leading-tight"
          data-aos="fade-up" data-aos-delay="250">
          Cerita singkat dari klien yang
          <span
            class="bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED] bg-clip-text text-transparent">
            sudah percaya
          </span>
          ke Velora.
        </h2>

        <p class="mt-4 text-slate-600 leading-relaxed max-w-xl mx-auto lg:mx-0"
          data-aos="fade-up" data-aos-delay="350">
          Fokus kami: desain rapi, loading cepat, dan komunikasi enak.
          Ini beberapa feedback yang sering kami terima.
        </p>

        <div class="mt-7 flex flex-col sm:flex-row items-center sm:items-start gap-4"
          data-aos="fade-up" data-aos-delay="450">
          <a href="{{ route('about.index') }}"
            class="sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-semibold text-white
                   bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]
                   shadow-[0_18px_45px_rgba(105,111,199,0.22)]
                   hover:brightness-105 transition">
            <i class="fa-solid fa-circle-info text-sm"></i>
            Tentang Kami
          </a>
        </div>
      </div>

      <!-- RIGHT -->
      <div class="lg:col-span-7 max-w-xl mx-auto lg:max-w-none"
           data-aos="fade-left" data-aos-delay="200"
           x-data="{
            i: 0,
            timer: null,
            items: [
  {
    name: 'Andi Pratama',
    role: 'Owner • Kuliner',
    quote: 'Tampilannya modern dan mudah dipahami pelanggan. Website ini sangat membantu bisnis kami terlihat lebih profesional.'
  },
  {
    name: 'Budi Santoso',
    role: 'Founder • Retail',
    quote: 'Proses pengerjaannya cepat dan komunikasinya jelas. Hasil akhirnya juga ringan dan nyaman dibuka di HP.'
  },
  {
    name: 'Rizky Maulana',
    role: 'Owner • Jasa',
    quote: 'Detail desainnya diperhatikan dengan baik. Website jadi terlihat bersih dan mudah digunakan oleh pelanggan.'
  },
  {
    name: 'Dewi Kartika',
    role: 'Admin • UMKM',
    quote: 'Dari awal konsultasi sampai website selesai semuanya terarah. Sangat membantu untuk promosi bisnis online.'
  }
],
            next() { this.i = (this.i + 1) % this.items.length },
            prev() { this.i = (this.i - 1 + this.items.length) % this.items.length },
            go(n) { this.i = n },
            start() { this.timer = setInterval(() => this.next(), 5500) },
            stop() { if (this.timer) clearInterval(this.timer); this.timer = null }
           }"
           x-init="start()"
           @mouseenter="stop()"
           @mouseleave="start()">

        <!-- CARD (kembali seperti sebelumnya) -->
        <div
          class="relative rounded-3xl border border-slate-200 bg-white/70 backdrop-blur p-6 sm:p-8
                 shadow-[0_25px_70px_rgba(15,23,42,0.10)] overflow-hidden"
          data-aos="zoom-in" data-aos-delay="300">

          <div
            class="pointer-events-none absolute -inset-16 rounded-full blur-3xl
                   bg-gradient-to-tr from-[#20B7FF]/12 via-[#696FC7]/10 to-[#7C3AED]/10">
          </div>

          <div class="relative">

            <!-- Quote icon (original style) -->
            <div
              class="mb-5 inline-flex h-10 w-10 items-center justify-center rounded-2xl
                     bg-gradient-to-r from-[#20B7FF]/15 via-[#696FC7]/15 to-[#7C3AED]/15
                     border border-slate-200"
              data-aos="fade-up" data-aos-delay="350">
              <svg class="h-5 w-5 text-slate-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10 11H6V7h4v4zm8 0h-4V7h4v4z"></path>
                <path d="M6 11c0 6-2 8-2 8"></path>
                <path d="M14 11c0 6-2 8-2 8"></path>
              </svg>
            </div>

            <!-- Quote text -->
            <p class="text-slate-700 text-base sm:text-lg leading-relaxed min-h-[120px] sm:min-h-[96px]"
               data-aos="fade-up" data-aos-delay="400"
               x-text="items[i].quote"></p>

            <!-- Footer -->
            <div class="mt-6 flex items-center gap-3" data-aos="fade-up" data-aos-delay="450">
              <div
                class="h-11 w-11 rounded-2xl bg-slate-100 border border-slate-200 grid place-items-center
                       text-sm font-bold text-slate-700"
                x-text="items[i].name.split(' ').slice(0,2).map(w=>w[0]).join('')"></div>
              <div>
                <div class="font-semibold text-slate-900" x-text="items[i].name"></div>
                <div class="text-sm text-slate-500" x-text="items[i].role"></div>
              </div>
            </div>

            <!-- Controls (kembali seperti sebelumnya) -->
            <div class="mt-7 flex flex-col sm:flex-row sm:items-center justify-between gap-5 sm:gap-0"
              data-aos="fade-up" data-aos-delay="500">

              <div class="flex sm:items-center gap-2">
                <button @click="prev()"
                  class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white
                         text-slate-700 hover:bg-slate-50 transition">
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M15 18l-6-6 6-6"></path>
                  </svg>
                </button>

                <button @click="next()"
                  class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white
                         text-slate-700 hover:bg-slate-50 transition">
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 18l6-6-6-6"></path>
                  </svg>
                </button>
              </div>

              <!-- Dots -->
              <div class="flex sm:items-center gap-2">
                <template x-for="(dot, idx) in items" :key="idx">
                  <button @click="go(idx)"
                    :class="i === idx
                      ? 'w-7 bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]'
                      : 'w-2 bg-slate-300'"
                    class="h-2 rounded-full transition-all">
                  </button>
                </template>
              </div>

            </div>

          </div>
        </div>

      </div>

    </div>
  </div>
</section>