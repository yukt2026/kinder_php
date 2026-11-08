    </div> <!-- Close max-w-6xl -->
    
    <!-- Mobile Sticky Bottom Navigation Bar (Matching Reference Image 1) -->
    <div class="fixed bottom-0 left-0 right-0 z-50 bg-[#0A0A0A]/95 backdrop-blur-md border-t border-white/[0.04] md:hidden block shadow-2xl animate-fade-in" style="padding-bottom: env(safe-area-inset-bottom, 0px);">
        <div class="flex justify-around items-center h-16 px-0.5 text-[9px] font-bold">
            <?php 
            $curr = basename($_SERVER['PHP_SELF']); 
            ?>
            <a href="index.php" class="flex flex-col items-center justify-center py-1 flex-1 transition-colors tap-target <?= $curr == 'index.php' ? 'text-[#d8a735]' : 'text-slate-500 hover:text-slate-300' ?>">
                <span class="material-symbols-rounded text-[22px] <?= $curr == 'index.php' ? 'fill-1' : '' ?>">grid_view</span>
                <span class="mt-0.5">Home</span>
            </a>
            <a href="baparis.php" class="flex flex-col items-center justify-center py-1 flex-1 transition-colors tap-target <?= $curr == 'baparis.php' ? 'text-[#d8a735]' : 'text-slate-500 hover:text-slate-300' ?>">
                <span class="material-symbols-rounded text-[22px] <?= $curr == 'baparis.php' ? 'fill-1' : '' ?>">group</span>
                <span class="mt-0.5">Bapari</span>
            </a>
            <a href="karigors.php" class="flex flex-col items-center justify-center py-1 flex-1 transition-colors tap-target <?= $curr == 'karigors.php' ? 'text-[#d8a735]' : 'text-slate-500 hover:text-slate-300' ?>">
                <span class="material-symbols-rounded text-[22px] <?= $curr == 'karigors.php' ? 'fill-1' : '' ?>">engineering</span>
                <span class="mt-0.5">Karigor</span>
            </a>
            <a href="entry.php" class="flex flex-col items-center justify-center py-1 flex-1 transition-colors tap-target <?= $curr == 'entry.php' ? 'text-[#d8a735]' : 'text-slate-500 hover:text-slate-300' ?>">
                <span class="material-symbols-rounded text-[22px] <?= $curr == 'entry.php' ? 'fill-1' : '' ?>">add_circle</span>
                <span class="mt-0.5">Entry</span>
            </a>
            <a href="reports.php" class="flex flex-col items-center justify-center py-1 flex-1 transition-colors tap-target <?= ($curr == 'reports.php' || $curr == 'ledger.php' || $curr == 'karigor_ledger.php') ? 'text-[#d8a735]' : 'text-slate-500 hover:text-slate-300' ?>">
                <span class="material-symbols-rounded text-[22px] <?= ($curr == 'reports.php' || $curr == 'ledger.php' || $curr == 'karigor_ledger.php') ? 'fill-1' : '' ?>">book</span>
                <span class="mt-0.5">Ledger</span>
            </a>
            <?php if ($isAdmin): ?>
                <a href="admin.php" class="flex flex-col items-center justify-center py-1 flex-1 transition-colors tap-target <?= $curr == 'admin.php' ? 'text-[#d8a735]' : 'text-slate-500 hover:text-slate-300' ?>">
                    <span class="material-symbols-rounded text-[22px] <?= $curr == 'admin.php' ? 'fill-1' : '' ?>">shield</span>
                    <span class="mt-0.5">Admin</span>
                </a>
            <?php endif; ?>
            <a href="settings.php" class="flex flex-col items-center justify-center py-1 flex-1 transition-colors tap-target <?= $curr == 'settings.php' ? 'text-[#d8a735]' : 'text-slate-500 hover:text-slate-300' ?>">
                <span class="material-symbols-rounded text-[22px] <?= $curr == 'settings.php' ? 'fill-1' : '' ?>">settings</span>
                <span class="mt-0.5">Settings</span>
            </a>
        </div>
    </div>


    <!-- PWA Install Action Prompt Card -->
    <?php if (isset($_SESSION['user_id'])): ?>
        <div id="installAppContainer" class="hidden max-w-md mx-auto px-4 mt-8 mb-4">
            <button onclick="installPWA()" class="w-full flex items-center justify-center space-x-2 py-3.5 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-600 text-slate-950 font-bold text-sm shadow-xl shadow-amber-500/10 tap-target">
                <span class="material-symbols-rounded">download</span>
                <span>Download Dasgold App</span>
            </button>
        </div>
    <?php endif; ?>

    <!-- Desktop Footer -->
    <footer class="hidden md:block mt-16 mb-8 text-center text-xs text-slate-600">
        <p>&copy; 2026 Dasgold Ledger Pro. All rights reserved.</p>
    </footer>

    <!-- Service Worker Installer & PWA Install Listener Script -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                // Register normal sw.js
                navigator.serviceWorker.register('sw.js')
                    .then((reg) => console.log('PWA Service Worker: Registered', reg.scope))
                    .catch((err) => console.error('PWA Service Worker: Registration failed', err));
            });
        }

        // Initialize Tom Select for searchable dropdowns
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof TomSelect !== 'undefined') {
                document.querySelectorAll('.searchable-select').forEach(function(el) {
                    new TomSelect(el, {
                        create: false,
                        sortField: {
                            field: "text",
                            direction: "asc"
                        },
                        placeholder: el.getAttribute('placeholder') || 'Search...',
                    });
                });
            }
        });

        // PWA Native Installation Prompter Logic
        let deferredPrompt;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            const installContainer = document.getElementById('installAppContainer');
            if (installContainer) {
                installContainer.classList.remove('hidden');
            }
        });

        function installPWA() {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then((choiceResult) => {
                if (choiceResult.outcome === 'accepted') {
                    console.log('User accepted the PWA install prompt');
                }
                deferredPrompt = null;
                const installContainer = document.getElementById('installAppContainer');
                if (installContainer) {
                    installContainer.classList.add('hidden');
                }
            });
        }

        // iOS Safari Specific Installation Tooltip Fallback
        window.addEventListener('DOMContentLoaded', () => {
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
            const isStandalone = window.matchMedia('(display-mode: standalone)').matches;
            if (isIOS && !isStandalone) {
                const installContainer = document.getElementById('installAppContainer');
                if (installContainer) {
                    const installBtn = installContainer.querySelector('button');
                    if (installBtn) {
                        installBtn.outerHTML = `
                            <div class="w-full bg-[#1E293B] border border-white/[0.06] p-4 rounded-2xl text-center text-xs text-slate-300 shadow-xl">
                                <span class="font-semibold text-white flex items-center justify-center mb-1.5"><span class="material-symbols-rounded text-base text-[#d8a735] mr-1.5">download</span> Install Dasgold App</span>
                                To install on iPhone, tap the <span class="font-bold text-white">Share</span> button below and select <span class="font-bold text-[#d8a735]">Add to Home Screen</span>.
                            </div>
                        `;
                    }
                    installContainer.classList.remove('hidden');
                }
            }
        });

        // Global Double-Submission Prevention Strategy
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (form && form.method && form.method.toUpperCase() === 'POST') {
                if (form.getAttribute('data-submitting') === 'true') {
                    e.preventDefault();
                    return false;
                }
                form.setAttribute('data-submitting', 'true');
                
                const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
                if (submitBtn && !submitBtn.hasAttribute('data-no-disable')) {
                    const origHtml = submitBtn.innerHTML;
                    // Asynchronously disable so button name/value submits properly on mobile & webview
                    setTimeout(() => {
                        submitBtn.disabled = true;
                        submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
                        submitBtn.innerHTML = '<span class="inline-block animate-spin mr-1.5">⏳</span> Saving...';
                    }, 20);
                    
                    setTimeout(() => {
                        form.removeAttribute('data-submitting');
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                        submitBtn.innerHTML = origHtml;
                    }, 8000);
                }
            }
        }, true);
    </script>
</body>
</html>
