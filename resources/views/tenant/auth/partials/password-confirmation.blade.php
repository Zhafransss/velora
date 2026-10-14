{{-- Kolom "Ulangi password" dengan pemeriksaan langsung saat mengetik. Server tetap memvalidasi (aturan `confirmed`,
     pesan di PasswordMessages) bila JavaScript mati. Membutuhkan input #password di form yang sama. --}}
<label for="password_confirmation">Ulangi password</label>
<input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
       aria-describedby="password_confirmation_hint">
<div class="err" id="password_confirmation_hint" aria-live="polite"></div>

<script>
    (() => {
        const password = document.getElementById('password');
        const confirmation = document.getElementById('password_confirmation');
        const hint = document.getElementById('password_confirmation_hint');

        if (!password || !confirmation || !hint) {
            return;
        }

        const check = () => {
            if (confirmation.value === '') {
                confirmation.setCustomValidity('');
                hint.textContent = '';
                return;
            }

            const same = confirmation.value === password.value;

            // Browser menahan pengiriman form selama pesan validitas tidak kosong.
            confirmation.setCustomValidity(same ? '' : 'Password tidak sama.');
            hint.textContent = same ? '✓ Password sama' : 'Password tidak sama dengan kolom Password.';
            hint.style.color = same ? 'var(--ok)' : 'var(--bad)';
        };

        password.addEventListener('input', check);
        confirmation.addEventListener('input', check);
    })();
</script>
