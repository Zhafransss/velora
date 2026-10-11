@props(['service', 'packages'])

<section id="paket" style="background:#f8fafc; padding:60px 20px; font-family:sans-serif;">
    <div style="max-width:1100px; margin:auto;">

        <div style="max-width:600px;">
            <h2 style="font-size:28px; font-weight:bold; margin-bottom:10px;">
                Paket {{ $service->category->name }}
            </h2>
            <p style="color:#64748b;">
                Pilih paket sesuai target dan kebutuhan bisnis Anda.
            </p>
        </div>

        <div id="paket-grid" style="display:grid; grid-template-columns:repeat(3,1fr); gap:25px; margin-top:40px;">
            @foreach ($packages as $index => $pkg)
                <div 
                    id="card-{{ $index }}"
                    style="
                        position:relative;
                        background:#fff;
                        border: {{ $index == 1 ? '2px solid #7c3aed' : '1px solid #e2e8f0' }};
                        transform: {{ $index == 1 ? 'scale(1.05)' : 'scale(1)' }};
                        box-shadow: {{ $index == 1 ? '0 15px 35px rgba(0,0,0,0.12)' : 'none' }};
                        transition: transform 0.4s ease, box-shadow 0.3s;
                    "

                    onmouseover="
                        this.style.transform='{{ $index == 1 ? 'scale(1.08)' : 'scale(1.03)' }}';
                        this.style.boxShadow='0 20px 40px rgba(0,0,0,0.15)';
                    "
                    onmouseout="
                        this.style.transform='{{ $index == 1 ? 'scale(1.05)' : 'scale(1)' }}';
                        this.style.boxShadow='{{ $index == 1 ? '0 15px 35px rgba(0,0,0,0.12)' : 'none' }}';
                    "
                >

                    {{-- Badge --}}
                    @if ($index == 1)
                        <div style="
                            position:absolute;
                            top:-12px;
                            left:50%;
                            transform:translateX(-50%);
                            background:#7c3aed;
                            color:#fff;
                            font-size:12px;
                            padding:5px 12px;
                            border-radius:20px;
                        ">
                            Paling Populer
                        </div>
                    @endif

                    {{-- Top gradient --}}
                    <div style="height:4px; background:linear-gradient(to right, #20B7FF, #696FC7, #7C3AED);"></div>

                    <div style="padding:25px;">
                        <h3 style="font-size:16px; font-weight:bold;">
                            {{ $pkg->name }}
                        </h3>

                        <p style="font-size:14px; color:#64748b; margin-top:5px;">
                            {{ $pkg->description }}
                        </p>

                        <p style="margin-top:20px; font-size:12px; color:#94a3b8; text-transform:uppercase;">
                            Harga Paket
                        </p>

                        <div style="margin-top:5px; display:flex; align-items:baseline; gap:5px;">
                            <span style="font-size:14px; color:#64748b;">Rp</span>
                            <span style="font-size:28px; font-weight:bold;">
                                {{ number_format($pkg->price, 0, ',', '.') }}
                            </span>
                        </div>

                        {{-- FEATURES --}}
                        @if ($pkg->features->count())
                            <ul style="margin-top:20px; font-size:14px; color:#334155; padding:0; list-style:none;">
                                @foreach ($pkg->features as $feature)
                                    <li style="display:flex; align-items:flex-start; gap:8px; margin-bottom:8px;">
                                        <span style="
                                            display:inline-flex;
                                            align-items:center;
                                            justify-content:center;
                                            width:18px;
                                            height:18px;
                                            background:#7c3aed;
                                            color:#fff;
                                            font-size:12px;
                                            border-radius:50%;
                                            flex-shrink:0;
                                            margin-top:2px;
                                        ">
                                            ✓
                                        </span>
                                        <span>{{ $feature->text }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <a href="https://wa.me/628970038125?text={{ urlencode('Halo Velora Digital, saya tertarik dengan paket '.$pkg->name.' dengan harga Rp '.number_format($pkg->price,0,',','.').'. Bisa konsultasi lebih lanjut?') }}"
                           target="_blank"
                           style="
                                display:block;
                                margin-top:25px;
                                text-align:center;
                                padding:12px;
                                color:#fff;
                                text-decoration:none;
                                font-weight:600;
                                background: {{ $index == 1 
                                    ? 'linear-gradient(to right, #20B7FF, #696FC7, #7C3AED)'
                                    : '#7c3aed'  }};
                           ">
                           Pilih Paket Ini
                        </a>

                    </div>
                </div>
            @endforeach
        </div>

    </div>
</section>

{{-- SCRIPT FINAL --}}
<script>
document.addEventListener("DOMContentLoaded", function () {
    const grid = document.getElementById("paket-grid");
    const card = document.getElementById("card-1");

    // RESPONSIVE GRID
    function updateLayout() {
        const width = window.innerWidth;

        if (width < 640) {
            grid.style.gridTemplateColumns = "1fr";
        } else if (width < 1024) {
            grid.style.gridTemplateColumns = "1fr 1fr";
        } else {
            grid.style.gridTemplateColumns = "repeat(3,1fr)";
        }
    }

    updateLayout();
    window.addEventListener("resize", updateLayout);

    // ANIMASI NAIK TURUN HALUS
    if (card) {
        let t = 0;
        let hovering = false;

        card.addEventListener("mouseenter", () => hovering = true);
        card.addEventListener("mouseleave", () => hovering = false);

        setInterval(() => {
            if (hovering) return;

            t += 0.04;

            const y = Math.sin(t) * 10;

            card.style.transform = `translateY(${y}px) scale(1.05)`;

        }, 30);
    }
});
</script>