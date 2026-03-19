<?php
// Memo posting confirmation modal reused by create and edit screens.
?>
<div class="fixed inset-0 z-40 hidden items-center justify-center bg-slate-900/40 px-4 py-8 backdrop-blur-sm" data-memo-confirm-modal>
  <div class="mx-auto w-full max-w-2xl rounded-3xl bg-white shadow-2xl">
    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-600">Review Memo</p>
        <h2 class="text-2xl font-semibold text-slate-900">Confirm recipients</h2>
      </div>
      <button type="button" class="text-slate-500 hover:text-slate-700" data-memo-confirm-cancel aria-label="Close confirmation">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-6 w-6">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>
    <div class="px-6 py-5">
      <p class="text-sm text-slate-600">Double-check the audience before posting. Everyone listed below will receive the memo once you continue.</p>
      <div class="mt-4 space-y-4">
        <div data-memo-confirm-summary class="grid gap-3"></div>
        <div data-memo-confirm-empty class="rounded-2xl border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-700">
          <p class="font-semibold">No recipients selected</p>
          <p class="text-xs text-yellow-600">Select at least one department, role, or individual before posting.</p>
        </div>
      </div>
    </div>
    <div class="flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
      <button type="button" class="btn btn-outline" data-memo-confirm-cancel>Back</button>
      <button type="button" class="btn btn-primary" data-memo-confirm-yes>Post memo</button>
    </div>
  </div>
</div>
