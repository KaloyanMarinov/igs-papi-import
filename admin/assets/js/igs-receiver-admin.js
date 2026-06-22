/* global igsReceiver, ajaxurl */

/**
 * Generic AJAX helper — posts FormData and shows feedback.
 *
 * @param {FormData} data
 * @param {HTMLElement} feedbackEl
 */
function igsSend(data, feedbackEl) {
	feedbackEl.textContent = igsReceiver.i18n.saving;
	feedbackEl.className = 'igs-feedback';

	fetch(ajaxurl, { method: 'POST', body: data })
		.then(function (r) { return r.json(); })
		.then(function (res) {
			if (res.success) {
				feedbackEl.textContent = res.data.message;
				feedbackEl.className = 'igs-feedback success';
			} else {
				feedbackEl.textContent = (res.data && res.data.message) ? res.data.message : igsReceiver.i18n.error;
				feedbackEl.className = 'igs-feedback error';
			}
		})
		.catch(function () {
			feedbackEl.textContent = igsReceiver.i18n.requestFailed;
			feedbackEl.className = 'igs-feedback error';
		});
}

document.addEventListener('DOMContentLoaded', function () {

	// ── Connected Sites ─────────────────────────────────────────────────────────
	var addSiteBtn = document.getElementById('igs-add-site-btn');
	var siteLabelInput = document.getElementById('igs-site-label-input');
	var siteKeyInput = document.getElementById('igs-site-key-input');
	var siteFeedback = document.getElementById('igs-site-feedback');
	var sitesTbody = document.getElementById('igs-sites-tbody');

	if (addSiteBtn) {
		addSiteBtn.addEventListener('click', function () {
			var data = new FormData();
			data.append('action', 'igs_receiver_add_site');
			data.append('nonce', igsReceiver.nonceAddSite);
			data.append('label', siteLabelInput.value.trim());
			data.append('api_key', siteKeyInput.value.trim());

			siteFeedback.textContent = igsReceiver.i18n.saving;
			siteFeedback.className = 'igs-feedback';

			fetch(ajaxurl, { method: 'POST', body: data })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res.success) {
						// Re-render to show the new row with a masked key.
						window.location.reload();
					} else {
						siteFeedback.textContent = (res.data && res.data.message) ? res.data.message : igsReceiver.i18n.error;
						siteFeedback.className = 'igs-feedback error';
					}
				})
				.catch(function () {
					siteFeedback.textContent = igsReceiver.i18n.requestFailed;
					siteFeedback.className = 'igs-feedback error';
				});
		});
	}

	// Remove buttons — event delegation on the table body.
	if (sitesTbody) {
		sitesTbody.addEventListener('click', function (e) {
			var btn = e.target.closest('.igs-remove-site-btn');
			if (!btn) {
				return;
			}

			var row = btn.closest('tr');
			var id = row ? row.getAttribute('data-id') : '';
			if (!id) {
				return;
			}

			if (!window.confirm(igsReceiver.i18n.confirmRemove)) {
				return;
			}

			var data = new FormData();
			data.append('action', 'igs_receiver_remove_site');
			data.append('nonce', igsReceiver.nonceRemoveSite);
			data.append('id', id);

			btn.disabled = true;

			fetch(ajaxurl, { method: 'POST', body: data })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res.success) {
						row.parentNode.removeChild(row);
					} else {
						btn.disabled = false;
						window.alert((res.data && res.data.message) ? res.data.message : igsReceiver.i18n.error);
					}
				})
				.catch(function () {
					btn.disabled = false;
					window.alert(igsReceiver.i18n.requestFailed);
				});
		});
	}

	// ── Default Author ────────────────────────────────────────────────────────
	var authorBtn = document.getElementById('igs-save-author-btn');
	var authorSelect = document.getElementById('igs-author-select');
	var authorFeedback = document.getElementById('igs-author-feedback');

	if (authorBtn) {
		authorBtn.addEventListener('click', function () {
			var data = new FormData();
			data.append('action', 'igs_receiver_save_author');
			data.append('nonce', igsReceiver.nonceAuthor);
			data.append('author_id', authorSelect.value);
			igsSend(data, authorFeedback);
		});
	}

	// ── Title Word Replacements ───────────────────────────────────────────────
	var replacementsBtn = document.getElementById('igs-save-replacements-btn');
	var wordsArea = document.getElementById('igs-title-words');
	var translationsArea = document.getElementById('igs-title-translations');
	var replacementsFeedback = document.getElementById('igs-replacements-feedback');

	if (replacementsBtn) {
		replacementsBtn.addEventListener('click', function () {
			var data = new FormData();
			data.append('action', 'igs_receiver_save_replacements');
			data.append('nonce', igsReceiver.nonceReplacements);
			data.append('words', wordsArea.value);
			data.append('translations', translationsArea.value);
			igsSend(data, replacementsFeedback);
		});
	}

});
