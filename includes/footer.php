    </main>
    <footer class="mt-auto px-5 py-3 text-xs text-gray-400 border-t border-gray-100">&copy; <?= date('Y') ?> <?= htmlspecialchars(COMPANY_NAME) ?></footer>
  </div>
</div>
<!-- Authorization Modal -->
<div id="authzModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" data-authz-close></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl ring-1 ring-black/5 w-full max-w-sm">
      <div class="px-4 py-3 border-b font-semibold flex items-center justify-between">
        <span>Authorization Required</span>
        <button class="text-gray-500 hover:text-gray-700" title="Close" data-authz-close aria-label="Close">✕</button>
      </div>
      <div class="p-4 space-y-3">
        <div id="authzNotice" class="space-y-3 text-sm text-gray-700">
          <div>
            <div id="authzActionLabel" class="font-medium text-gray-900"></div>
            <div id="authzRequirement" class="text-xs text-gray-500"></div>
          </div>
          <p class="text-sm text-gray-600">This action requires elevated authorization to continue.</p>
          <div class="mt-1"><button id="authzStart" class="underline text-blue-600">Authorize</button></div>
        </div>
        <form id="authzForm" class="space-y-3 hidden">
          <div class="space-y-1 text-sm text-gray-700">
            <div id="authzActionLabelForm" class="font-medium text-gray-900"></div>
            <div id="authzRequirementForm" class="text-xs text-gray-500"></div>
            <div>Enter authorized user credentials to proceed.</div>
          </div>
          <div>
            <label class="block text-xs text-gray-500">Email of Authorized User</label>
            <input type="email" class="w-full border rounded px-3 py-2" id="authzEmail" autocomplete="username" />
            <div id="authzEmailError" class="field-error hidden"></div>
          </div>
          <div>
            <label class="block text-xs text-gray-500">Password</label>
            <input type="password" class="w-full border rounded px-3 py-2" id="authzPassword" autocomplete="current-password" />
          </div>
          <div class="flex justify-end gap-2">
            <button type="button" class="px-3 py-2 rounded border" data-authz-close>Cancel</button>
            <button type="submit" class="px-3 py-2 rounded bg-blue-600 text-white">Authorize</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <!-- Hidden stash for original form -->
  <div id="authzFormStash" class="hidden"></div>
  <input type="hidden" id="authzTargetFormId" />
  <input type="hidden" id="authzRequiredLevel" />
  <input type="hidden" id="authzModule" />
  <input type="hidden" id="authzAction" />
</div>

<!-- Confirm Modal -->
<div id="confirmModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" data-confirm-close></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl ring-1 ring-black/5 w-full max-w-sm">
      <div class="px-4 py-3 border-b font-semibold flex items-center justify-between">
        <span>Confirm Action</span>
        <button class="text-gray-500 hover:text-gray-700" title="Close" data-confirm-close aria-label="Close">✕</button>
      </div>
      <div class="p-4 space-y-4">
        <div id="confirmMessage" class="text-sm text-gray-700">Are you sure?</div>
        <div class="flex justify-end gap-2">
          <button type="button" class="px-3 py-2 rounded border" data-confirm-close>Cancel</button>
          <button type="button" class="px-3 py-2 rounded bg-red-600 text-white" id="confirmYes">Confirm</button>
        </div>
      </div>
    </div>
  </div>
</div>
<?php // Bump asset version to invalidate stale cached JS (auth modal, payroll updates)
$assetVer = '20250210a'; ?>
<script>window.__APP_VER='<?= $assetVer ?>';</script>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= $assetVer ?>"></script>
</body>
</html>
