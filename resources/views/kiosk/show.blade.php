<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Visitor Kiosk - {{ config('app.name') }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        html, body {
            width: 100%;
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            overflow: hidden;
        }
        .kiosk-container {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .logo {
            position: absolute;
            top: 20px;
            left: 20px;
            font-size: 24px;
            font-weight: bold;
            color: white;
        }
        .video-section {
            width: 100%;
            max-width: 600px;
            margin-bottom: 20px;
        }
        .video-container {
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            aspect-ratio: 4/3;
        }
        video {
            width: 100%;
            height: 100%;
            display: block;
            transform: scaleX(-1); /* mirror the preview so it feels like a normal front-facing camera */
        }
        .status-bar {
            background: rgba(255,255,255,0.95);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 20px;
            min-height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .status-message {
            font-size: 18px;
            font-weight: 500;
            color: #333;
        }
        .welcome {
            font-size: 20px;
            font-weight: 600;
            color: #28a745;
        }
        .error {
            color: #dc3545;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            color: white;
        }
        .btn-primary {
            background: #28a745;
        }
        .btn-primary:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-2px);
        }
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .auth-toggle {
            margin-bottom: 15px;
            text-align: center;
        }
        .btn-link {
            background: transparent;
            color: white;
            border: 2px solid rgba(255,255,255,0.6);
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
        }
        .btn-link:hover {
            background: rgba(255,255,255,0.15);
        }
        .setup-prompt {
            background: rgba(255,255,255,0.95);
            padding: 30px;
            border-radius: 12px;
            text-align: center;
            max-width: 400px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .setup-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #333;
        }
        .setup-input {
            width: 100%;
            padding: 12px;
            font-size: 16px;
            border: 2px solid #ddd;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .setup-input:focus {
            outline: none;
            border-color: #667eea;
        }
        .setup-btn {
            width: 100%;
            padding: 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
        .setup-btn:hover {
            background: #5568d3;
        }
    </style>
</head>
<body data-kiosk-id="{{ $kiosk->kiosk_id }}">
    <div class="kiosk-container" id="main-view" style="display:none;">
        <div class="logo">{{ config('app.name') }} Kiosk</div>

        <div class="video-section">
            <div class="video-container">
                <video id="webcam" autoplay playsinline></video>
            </div>
        </div>

        <div class="status-bar">
            <div class="status-message" id="status-message">Ready for face recognition...</div>
        </div>

        <div class="auth-toggle" id="auth-toggle"></div>

        <div class="action-buttons" id="action-buttons"></div>
    </div>

    <div class="kiosk-container" id="setup-view">
        <div class="setup-prompt">
            <div class="setup-title">Kiosk Setup</div>
            <p style="margin-bottom: 20px; color: #666;">Enter your kiosk authentication token</p>
            <input type="password" class="setup-input" id="token-input" placeholder="Kiosk Token" autocomplete="off">
            <button class="setup-btn" onclick="submitToken()">Continue</button>
        </div>
    </div>

    <div class="kiosk-container" id="unauthorized-view" style="display:none;">
        <div class="setup-prompt">
            <div class="setup-title" style="color: #dc3545;">⚠ Unauthorized Kiosk</div>
            <p style="margin-bottom: 20px; color: #666;">This kiosk's token is missing or invalid. Face recognition cannot start until a valid token is entered.</p>
            <button class="setup-btn" onclick="resetToken()">Enter a Different Token</button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    <script>
        const kioskId = '{{ $kiosk->kiosk_id }}';
        let kioskToken = localStorage.getItem('kiosk_token_' + kioskId);
        let currentVisitorRequest = null;
        let isProcessingAction = false;
        let stream = null;
        let modelsLoaded = false;
        let noFaceStreak = 0;

        const MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';

        // State machine for kiosk flow
        const STATES = {
            IDLE: 'IDLE',                    // Ready to recognize faces
            DETECTED: 'DETECTED',            // Match found (face or QR), showing action buttons
            PROCESSING: 'PROCESSING',        // Processing an action (entry/exit)
            QR_SCAN: 'QR_SCAN',              // Scanning a QR code instead of a face
        };
        let currentState = STATES.IDLE;
        let lastRecognizedDirectoryId = null;
        let lastAuthMethod = 'FACE';
        let faceFailStreak = 0;
        const MAX_FACE_FAIL_STREAK = 3;
        // Which mode to return to once the current interaction finishes -
        // set only at the moment a match succeeds, based on which path
        // produced it. A QR-originated match must return to QR_SCAN, not
        // silently fall back to face mode; only the explicit "Back to Face
        // Recognition" button may do that.
        let modeBeforeDetection = STATES.IDLE;

        function submitToken() {
            const token = document.getElementById('token-input').value.trim();
            if (!token) {
                alert('Please enter a token');
                return;
            }
            localStorage.setItem('kiosk_token_' + kioskId, token);
            kioskToken = token;
            initialize();
        }

        function showView(id) {
            ['setup-view', 'main-view', 'unauthorized-view'].forEach(v => {
                document.getElementById(v).style.display = (v === id) ? 'flex' : 'none';
            });
        }

        function resetToken() {
            localStorage.removeItem('kiosk_token_' + kioskId);
            kioskToken = null;
            showView('setup-view');
        }

        async function initialize() {
            if (!kioskToken) {
                showView('setup-view');
                return;
            }

            // Verify the token server-side BEFORE showing the recognition
            // UI or touching the webcam - an invalid/missing token must
            // never reach the camera-init step at all.
            let tokenValid = false;
            try {
                const response = await fetch(`/kiosk/${kioskId}/verify-token`, {
                    headers: { 'X-KIOSK-TOKEN': kioskToken },
                });
                tokenValid = response.ok;
            } catch (err) {
                tokenValid = false;
            }

            if (!tokenValid) {
                showView('unauthorized-view');
                return;
            }

            showView('main-view');

            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } }
                });
                document.getElementById('webcam').srcObject = stream;
            } catch (err) {
                updateStatus('Camera access denied', 'error');
                return;
            }

            await loadModels();
            updateStatus('Scan your face...', 'info');
            updateAuthToggle();
            detectionTick();
        }

        async function loadModels() {
            updateStatus('Loading face recognition models...', 'info');
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
            ]);
            modelsLoaded = true;
        }

        function updateStatus(message, type = 'info') {
            const el = document.getElementById('status-message');
            el.textContent = message;
            el.className = 'status-message ' + (type === 'error' ? 'error' : type === 'success' ? 'welcome' : '');
        }

        function showActionButtons(state) {
            const buttonsHtml = [];

            // null/undefined means "no one is recognized yet" (idle, QR-scan
            // mode, request_completed) - show nothing. Only an explicit
            // 'no_session' status (a real match with no prior visit) shows
            // Enter Farm.
            if (!state) {
                // no buttons
            } else if (state.status === 'no_session') {
                buttonsHtml.push('<button class="btn btn-primary" onclick="processAction(\'first_entry\')">✓ Enter Farm</button>');
            } else if (state.status === 'Inside') {
                buttonsHtml.push('<button class="btn btn-warning" onclick="processAction(\'temporary_exit\')">🚪 Go Outside</button>');
                buttonsHtml.push('<button class="btn btn-danger" onclick="processAction(\'final_exit\')">👋 Leave Farm</button>');
            } else if (state.status === 'Outside') {
                buttonsHtml.push('<button class="btn btn-primary" onclick="processAction(\'return\')">↩️ Return</button>');
                buttonsHtml.push('<button class="btn btn-danger" onclick="processAction(\'final_exit\')">👋 Leave Farm</button>');
            }

            document.getElementById('action-buttons').innerHTML = buttonsHtml.join('');
        }

        // The QR toggle is reachable manually at any time from IDLE, and
        // automatically after 3 failed face attempts - both land here.
        function updateAuthToggle() {
            const el = document.getElementById('auth-toggle');
            if (currentState === STATES.IDLE) {
                el.innerHTML = '<button class="btn-link" onclick="enterQrScanMode()">📱 Scan QR Code Instead</button>';
            } else if (currentState === STATES.QR_SCAN) {
                el.innerHTML = '<button class="btn-link" onclick="backToFaceRecognition()">📷 Back to Face Recognition</button>';
            } else {
                el.innerHTML = '';
            }
        }

        function enterQrScanMode() {
            currentState = STATES.QR_SCAN;
            faceFailStreak = 0;
            updateStatus('Scan your QR Code...', 'info');
            showActionButtons(null);
            updateAuthToggle();
        }

        function backToFaceRecognition() {
            currentState = STATES.IDLE;
            modeBeforeDetection = STATES.IDLE;
            faceFailStreak = 0;
            updateStatus('Scan your face...', 'info');
            showActionButtons(null);
            updateAuthToggle();
        }

        async function processAction(action) {
            if (!currentVisitorRequest) {
                return;
            }

            // Move to PROCESSING state
            currentState = STATES.PROCESSING;
            isProcessingAction = true;

            try {
                const video = document.getElementById('webcam');
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0);
                const photoBase64 = canvas.toDataURL('image/jpeg');

                updateStatus('Processing...', 'info');
                const response = await fetch(`/kiosk/${kioskId}/entry`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-KIOSK-TOKEN': kioskToken,
                    },
                    body: JSON.stringify({
                        visitor_request_id: currentVisitorRequest.visitor_request_id,
                        action: action,
                        photo: photoBase64,
                        authentication_method: lastAuthMethod,
                    }),
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(`Server error (${response.status}): ${errorText.substring(0, 100)}`);
                }

                const result = await response.json();
                if (result.success) {
                    updateStatus(result.message, 'success');
                    currentVisitorRequest.session_state = {
                        status: result.session_status,
                    };
                    showActionButtons(currentVisitorRequest.session_state);

                    // After action completes, return to scanning for the next visitor
                    setTimeout(() => {
                        finishInteraction();
                    }, 2000);
                } else {
                    updateStatus(result.message, 'error');
                    // On error, return to DETECTED state to allow retry
                    currentState = STATES.DETECTED;
                }
            } catch (err) {
                updateStatus('Error: ' + err.message, 'error');
                // On error, return to DETECTED state to allow retry
                currentState = STATES.DETECTED;
            } finally {
                isProcessingAction = false;
            }
        }

        // Ends the current recognized interaction and returns to whichever
        // mode the visitor was actually using before they were detected -
        // QR_SCAN stays QR_SCAN (e.g. a receptionist processing several QR
        // visitors in a row), IDLE stays IDLE. Never force face mode.
        function finishInteraction() {
            lastRecognizedDirectoryId = null;
            lastAuthMethod = 'FACE';
            currentVisitorRequest = null;
            noFaceStreak = 0;
            faceFailStreak = 0;
            showActionButtons(null);

            if (modeBeforeDetection === STATES.QR_SCAN) {
                currentState = STATES.QR_SCAN;
                updateStatus('Scan your QR Code...', 'info');
            } else {
                currentState = STATES.IDLE;
                updateStatus('Scan your face...', 'info');
            }

            updateAuthToggle();
        }

        // Self-scheduling loop: never overlaps itself, always waits for the
        // previous detection to finish before scheduling the next one.
        async function detectionTick() {
            try {
                await runDetectionCycle();
            } catch (err) {
                console.error('Detection error:', err);
            }
            setTimeout(detectionTick, 600);
        }

        async function runDetectionCycle() {
            if (!stream || !modelsLoaded || currentState === STATES.PROCESSING) {
                return;
            }

            const video = document.getElementById('webcam');
            if (video.readyState < 2) {
                return;
            }

            if (currentState === STATES.QR_SCAN) {
                await runQrScanCycle(video);
                return;
            }

            const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 });

            if (currentState === STATES.DETECTED) {
                // Someone is already recognized and action buttons are shown.
                // Only check whether a face is still present - if it disappears,
                // finish the recognition immediately and go back to scanning.
                const presence = await faceapi.detectSingleFace(video, options);
                if (!presence) {
                    noFaceStreak++;
                    if (noFaceStreak >= 2) {
                        finishInteraction();
                    }
                } else {
                    noFaceStreak = 0;
                }
                return;
            }

            // IDLE state: look for an actual face and extract its real descriptor.
            const detection = await faceapi
                .detectSingleFace(video, options)
                .withFaceLandmarks()
                .withFaceDescriptor();

            if (!detection) {
                noFaceStreak = 0;
                return;
            }

            await attemptFaceRecognition(Array.from(detection.descriptor));
        }

        async function attemptFaceRecognition(descriptorArray) {
            if (currentState !== STATES.IDLE) {
                return;
            }

            try {
                const response = await fetch(`/kiosk/${kioskId}/recognize`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-KIOSK-TOKEN': kioskToken,
                    },
                    body: JSON.stringify({
                        descriptor: descriptorArray,
                    }),
                });

                await handleRecognitionResponse(response, 'FACE');
            } catch (err) {
                console.error('Recognition error:', err);
            }
        }

        async function runQrScanCycle(video) {
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height);

            if (code && code.data) {
                await attemptQrRecognition(code.data);
            }
        }

        async function attemptQrRecognition(qrValue) {
            if (currentState !== STATES.QR_SCAN) {
                return;
            }

            try {
                const response = await fetch(`/kiosk/${kioskId}/recognize`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-KIOSK-TOKEN': kioskToken,
                    },
                    body: JSON.stringify({
                        qr_value: qrValue,
                    }),
                });

                await handleRecognitionResponse(response, 'QR');
            } catch (err) {
                console.error('QR recognition error:', err);
            }
        }

        // Shared by both authentication paths - identical handling of
        // success, "already completed", and failure, so face and QR can
        // never diverge in how a recognition result is applied client-side.
        async function handleRecognitionResponse(response, authMethod) {
            let result;
            try {
                result = await response.json();
            } catch (err) {
                return;
            }

            if (response.ok && result.success) {
                currentState = STATES.DETECTED;
                // Remember which mode this match came from so finishInteraction()
                // returns here afterward instead of always defaulting to face mode.
                modeBeforeDetection = (authMethod === 'QR') ? STATES.QR_SCAN : STATES.IDLE;
                noFaceStreak = 0;
                faceFailStreak = 0;
                lastAuthMethod = authMethod;
                lastRecognizedDirectoryId = result.directory.directory_id;
                currentVisitorRequest = result;
                updateStatus(`Welcome, ${result.directory.full_name}!`, 'success');
                showActionButtons(result.session_state);
                updateAuthToggle();
                return;
            }

            if (result.type === 'request_completed') {
                updateStatus(result.message, 'error');
                showActionButtons(null);
                setTimeout(() => finishInteraction(), 3000);
                return;
            }

            if (['employee_detected', 'gatesale_detected', 'truck_detected', 'identity_not_supported', 'visitor_type_not_supported'].includes(result.type)) {
                updateStatus(result.message, 'info');
                showActionButtons(null);
                setTimeout(() => finishInteraction(), 3000);
                return;
            }

            updateStatus(result.message || 'Not recognized.', 'error');

            if (result.type === 'face_not_found') {
                showRegisterVisitorPlaceholder();
            }

            if (authMethod === 'FACE') {
                faceFailStreak++;
                if (faceFailStreak >= MAX_FACE_FAIL_STREAK) {
                    enterQrScanMode();
                }
            }
        }

        // Placeholder only (Phase 1) - creates no record and starts no
        // registration process. Wired up properly in a future phase.
        function showRegisterVisitorPlaceholder() {
            document.getElementById('action-buttons').innerHTML =
                '<button class="btn btn-secondary" onclick="registerVisitorPlaceholder()">Registration</button>';
        }

        function registerVisitorPlaceholder() {
            updateStatus(
                'Registration for Gatesale and Truck / Delivery visitors will be available in an upcoming update.',
                'info'
            );

            document.getElementById('action-buttons').innerHTML = '';
        }

        initialize();
    </script>
</body>
</html>
