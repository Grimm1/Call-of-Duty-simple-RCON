

function customConfirm(message, callback) {
    document.getElementById('confirm-message').textContent = message;
    const dialog = document.getElementById('confirm-dialog');
    dialog.style.display = 'flex';

    function closeDialog() {
        dialog.style.display = 'none';
        document.getElementById('confirm-yes').removeEventListener('click', yesClick);
        document.getElementById('confirm-no').removeEventListener('click', noClick);
    }

    function yesClick() {
        callback(true);
        closeDialog();
    }

    function noClick() {
        callback(false);
        closeDialog();
    }

    document.getElementById('confirm-yes').addEventListener('click', yesClick);
    document.getElementById('confirm-no').addEventListener('click', noClick);
}

function customMessage(message, onClose) {
    document.getElementById('message-text').textContent = message;
    const dialog = document.getElementById('message-dialog');
    dialog.style.display = 'flex';

    document.getElementById('message-ok').onclick = function () {
        dialog.style.display = 'none';
        this.onclick = null; // Clear the click handler
        if (onClose) onClose();
    };
}

function showAjaxMessage(message, onClose) {
    document.getElementById('message-text').textContent = message;
    const dialog = document.getElementById('message-dialog');
    dialog.style.display = 'flex';

    document.getElementById('message-ok').onclick = function () {
        dialog.style.display = 'none';
        this.onclick = null; // Clear the click handler
        if (onClose) onClose();
    };
}