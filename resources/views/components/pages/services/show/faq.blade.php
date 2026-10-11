@props(['faqs'])

<section class="bg-white py-16">
  <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">

    <div class="max-w-3xl">
      <h2 class="text-2xl sm:text-3xl font-semibold text-slate-900">FAQ</h2>
      <p class="mt-3 text-slate-600">Pertanyaan yang sering ditanyakan sebelum mulai.</p>
    </div>

    <div class="mt-10 grid gap-4">
      @foreach($faqs as $item)
        <div class="border border-slate-200 bg-white p-6">
          <p class="text-sm font-semibold text-slate-900">{{ $item['q'] }}</p>
          <p class="mt-2 text-sm text-slate-600 leading-relaxed">{{ $item['a'] }}</p>
        </div>
      @endforeach
    </div>

  </div>
</section>