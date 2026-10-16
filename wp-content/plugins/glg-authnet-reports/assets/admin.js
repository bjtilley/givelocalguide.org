(function () {
    var config = window.glgAuthnetExport;
    var form = document.getElementById('glg-authnet-export-form');
    if (!config || !form) {
        return;
    }

    var submit = form.querySelector('button[type="submit"]');
    var retry = document.getElementById('glg-authnet-retry');
    var progressBox = document.getElementById('glg-authnet-progress');
    var bar = progressBox.querySelector('progress');
    var status = document.getElementById('glg-authnet-status');
    var jobId = null;
    var format = 'xlsx';

    function post(action, fields) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', config.nonce);
        Object.keys(fields).forEach(function (key) {
            body.append(key, fields[key]);
        });

        return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error(config.i18n.badResult + ' (HTTP ' + response.status + ')');
                });
            })
            .then(function (json) {
                if (json.data && json.data.job) {
                    jobId = json.data.job;
                }
                if (!json.success) {
                    throw new Error((json.data && json.data.message) || config.i18n.badResult);
                }
                return json.data;
            });
    }

    function setBusy(busy) {
        submit.disabled = busy;
        retry.hidden = true;
        status.classList.remove('notice', 'notice-error');
    }

    function handle(progress) {
        bar.value = progress.percent;
        status.textContent = progress.message;

        if (!progress.done) {
            return post('glg_authnet_step', { job: jobId }).then(handle);
        }

        window.location.href = config.downloadUrl
            + '&job=' + encodeURIComponent(jobId)
            + '&format=' + encodeURIComponent(format);
        status.textContent = progress.message + ' ' + config.i18n.download;
        setBusy(false);
    }

    function fail(error) {
        submit.disabled = false;
        retry.hidden = !jobId;
        status.classList.add('notice', 'notice-error');
        status.textContent = error.message;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var data = new FormData(form);
        format = data.get('format') === 'csv' ? 'csv' : 'xlsx';
        jobId = null;

        setBusy(true);
        progressBox.hidden = false;
        bar.value = 0;
        status.textContent = config.i18n.starting;

        post('glg_authnet_start', {
            from: data.get('from'),
            to: data.get('to'),
            include_unsettled: data.get('include_unsettled') ? '1' : ''
        }).then(handle).catch(fail);
    });

    // Resume a failed job. Work already done (and cached) is kept.
    retry.addEventListener('click', function () {
        setBusy(true);
        post('glg_authnet_step', { job: jobId }).then(handle).catch(fail);
    });
})();
