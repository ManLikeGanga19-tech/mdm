{{--
    New bell notifications (alerts, crew handovers) pop up with a short chime, within ~15 s. Every 15 s this asks the
    site for the newest unread one (LatestNotificationController); if it is newer than the last one this browser saw,
    it shows it, refreshes the bell and plays the chime. The speaker button turns the sound off for this browser.
    Browsers only allow sound after someone has clicked on the page once.
--}}
<button type="button" id="mdm-chime-toggle" title="Notification sound"
        class="fi-icon-btn" style="display:inline-flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:.5rem;color:rgb(100 116 139)">
    <svg id="mdm-chime-on" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1.25rem;height:1.25rem">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" />
    </svg>
    <svg id="mdm-chime-off" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1.25rem;height:1.25rem;display:none">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 9.75 19.5 12m0 0 2.25 2.25M19.5 12l2.25-2.25M19.5 12l-2.25 2.25m-10.5-6 4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" />
    </svg>
</button>
<script>
    (() => {
        if (window.mdmChime) return; // once per page, even after Livewire navigation
        window.mdmChime = true;

        const url = @js(route('filament.admin.notifications.latest'));
        const store = {
            get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } },
            set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} },
        };
        const soundOn = () => store.get('mdm-chime') !== 'off';
        const showToggle = () => {
            document.getElementById('mdm-chime-on')?.style.setProperty('display', soundOn() ? '' : 'none');
            document.getElementById('mdm-chime-off')?.style.setProperty('display', soundOn() ? 'none' : '');
        };
        document.addEventListener('click', (e) => {
            if (e.target.closest('#mdm-chime-toggle')) {
                store.set('mdm-chime', soundOn() ? 'off' : 'on');
                showToggle();
                if (soundOn()) chime();
            }
        });
        document.addEventListener('livewire:navigated', showToggle);
        showToggle();

        let audio = null;
        const chime = () => {
            try {
                audio ??= new (window.AudioContext || window.webkitAudioContext)();
                if (audio.state === 'suspended') audio.resume();
                [[880, 0], [1320, 0.16]].forEach(([freq, at]) => { // two soft rising notes
                    const osc = audio.createOscillator(), gain = audio.createGain(), t = audio.currentTime + at;
                    osc.type = 'sine';
                    osc.frequency.value = freq;
                    gain.gain.setValueAtTime(0.0001, t);
                    gain.gain.exponentialRampToValueAtTime(0.25, t + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.45);
                    osc.connect(gain).connect(audio.destination);
                    osc.start(t);
                    osc.stop(t + 0.5);
                });
            } catch (e) {}
        };
        // Unlock audio on the first click (browser autoplay rules).
        document.addEventListener('click', () => { try { audio ??= new (window.AudioContext || window.webkitAudioContext)(); audio.resume(); } catch (e) {} }, { once: true });

        const check = async () => {
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!res.ok) return;
                const { latest } = await res.json();
                if (!latest) return;
                const seen = store.get('mdm-chime-seen');
                store.set('mdm-chime-seen', latest.id);
                if (seen === null || seen === latest.id) return; // first visit in this browser: just remember it

                window.Livewire?.dispatch('databaseNotificationsSent'); // refresh the bell now
                if (window.FilamentNotification) {
                    const toast = new window.FilamentNotification().title(latest.title || 'New notification').body(latest.body || '').duration(10000);
                    (latest.status === 'danger' ? toast.danger() : toast.info()).send();
                }
                if (soundOn()) chime();
            } catch (e) {}
        };
        check();
        setInterval(check, 15000); // also in a background tab (browsers slow it to about once a minute there)
        document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && check());
    })();
</script>
