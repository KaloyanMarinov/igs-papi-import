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

	// ── API Key ───────────────────────────────────────────────────────────────
	var keyBtn = document.getElementById('igs-save-key-btn');
	var keyInput = document.getElementById('igs-api-key-input');
	var keyFeedback = document.getElementById('igs-key-feedback');

	if (keyBtn) {
		keyBtn.addEventListener('click', function () {
			var data = new FormData();
			data.append('action', 'igs_receiver_save_key');
			data.append('nonce', igsReceiver.nonceKey);
			data.append('api_key', keyInput.value.trim());
			igsSend(data, keyFeedback);
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
