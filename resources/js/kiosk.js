import './bootstrap';

jQuery(document).ready(function($) {
    const kioskId = document.querySelector('[data-kiosk-id]')?.dataset.kioskId;
    if (!kioskId) return;

    let kioskToken = localStorage.getItem(`kiosk_token_${kioskId}`);
    let recognitionActive = false;

    if (!kioskToken) {
        showSetupPrompt();
    } else {
        initializeKiosk();
    }

    window.submitToken = function() {
        const tokenInput = document.getElementById('token-input');
        const token = tokenInput.value.trim();
        if (!token) {
            alert('Please enter a token');
            return;
        }
        localStorage.setItem(`kiosk_token_${kioskId}`, token);
        kioskToken = token;
        location.reload();
    };

    function showSetupPrompt() {
        document.getElementById('setup-view').style.display = 'flex';
        document.getElementById('main-view').style.display = 'none';
    }

    async function initializeKiosk() {
        document.getElementById('setup-view').style.display = 'none';
        document.getElementById('main-view').style.display = 'flex';

        try {
            const video = document.getElementById('webcam');
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
            });
            video.srcObject = stream;

            await video.play();

            if (typeof faceapi !== 'undefined' && faceapi.nets.tinyFaceDetector) {
                await faceapi.nets.tinyFaceDetector.loadFromUri('/vendor/face-api-models');
                await faceapi.nets.faceLandmark68Net.loadFromUri('/vendor/face-api-models');
                await faceapi.nets.faceDescriptorNet.loadFromUri('/vendor/face-api-models');
                startFaceRecognition(video);
            } else {
                console.warn('Face API not available');
                startPlaceholderRecognition();
            }
        } catch (err) {
            updateStatus('Camera access denied', 'error');
        }
    }

    async function startFaceRecognition(video) {
        recognitionActive = true;
        updateStatus('Ready for face recognition...');

        const recognitionInterval = setInterval(async () => {
            if (!recognitionActive) {
                clearInterval(recognitionInterval);
                return;
            }

            try {
                const detections = await faceapi
                    .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 224 }))
                    .withFaceDescriptors();

                if (detections && detections.descriptor) {
                    recognitionActive = false;
                    clearInterval(recognitionInterval);
                    await sendRecognition(Array.from(detections.descriptor));
                }
            } catch (err) {
                console.error('Recognition error:', err);
            }
        }, 2000);
    }

    function startPlaceholderRecognition() {
        updateStatus('Placeholder mode: using demo descriptor');
        setTimeout(() => {
            const placeholderDescriptor = Array(128).fill(0).map(() => Math.random());
            sendRecognition(placeholderDescriptor);
        }, 2000);
    }

    async function sendRecognition(descriptor) {
        try {
            const response = await $.ajax({
                url: `/kiosk/${kioskId}/recognize`,
                type: 'POST',
                contentType: 'application/json',
                headers: {
                    'X-KIOSK-TOKEN': kioskToken,
                },
                data: JSON.stringify({ descriptor: descriptor }),
            });

            if (response.success) {
                handleRecognitionSuccess(response);
            } else {
                updateStatus(response.message || 'Not recognized. Try QR scan.', 'error');
                recognitionActive = true;
            }
        } catch (err) {
            console.error('Recognition send error:', err);
            updateStatus('Error: ' + err.statusText, 'error');
            recognitionActive = true;
        }
    }

    function handleRecognitionSuccess(response) {
        const sessionState = response.session_state;
        const directory = response.directory;

        updateStatus(`Welcome, ${directory.full_name}!`, 'success');
        showActionButtons(sessionState);
    }

    function updateStatus(message, type = 'info') {
        const el = document.getElementById('status-message');
        if (el) {
            el.textContent = message;
            el.className = 'status-message ' + (type === 'error' ? 'error' : type === 'success' ? 'welcome' : '');
        }
    }

    function showActionButtons(state) {
        const buttonsHtml = [];

        if (!state || state.status === 'no_session') {
            buttonsHtml.push('<button class="btn btn-primary" onclick="window.processAction(\'first_entry\')">✓ Enter Farm</button>');
        } else if (state.status === 'Inside') {
            buttonsHtml.push('<button class="btn btn-warning" onclick="window.processAction(\'temporary_exit\')">🚪 Go Outside</button>');
            buttonsHtml.push('<button class="btn btn-danger" onclick="window.processAction(\'final_exit\')">👋 Leave Farm</button>');
        } else if (state.status === 'Outside') {
            buttonsHtml.push('<button class="btn btn-primary" onclick="window.processAction(\'return\')">↩️ Return</button>');
            buttonsHtml.push('<button class="btn btn-danger" onclick="window.processAction(\'final_exit\')">👋 Leave Farm</button>');
        }

        const buttonsEl = document.getElementById('action-buttons');
        if (buttonsEl) {
            buttonsEl.innerHTML = buttonsHtml.join('');
        }
    }

    window.processAction = function(action) {
        updateStatus('Processing...');
        const video = document.getElementById('webcam');
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0);
        const photoBase64 = canvas.toDataURL('image/jpeg');

        $.ajax({
            url: `/kiosk/${kioskId}/entry`,
            type: 'POST',
            contentType: 'application/json',
            headers: {
                'X-KIOSK-TOKEN': kioskToken,
            },
            data: JSON.stringify({
                visitor_request_id: window.currentVisitorRequestId,
                action: action,
                photo: photoBase64,
            }),
            success: function(result) {
                updateStatus(result.message, 'success');
                setTimeout(() => {
                    window.currentVisitorRequestId = null;
                    updateStatus('Ready for face recognition...');
                    document.getElementById('action-buttons').innerHTML = '';
                    recognitionActive = true;
                }, 2000);
            },
            error: function(err) {
                updateStatus('Error: ' + err.statusText, 'error');
            },
        });
    };
});
