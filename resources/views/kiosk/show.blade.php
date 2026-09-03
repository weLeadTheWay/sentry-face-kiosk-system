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
            max-width: 700px;
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

    <div class="kiosk-container" id="gatesale-view" style="display:none;">
        <div class="setup-prompt" style="max-width: 760px;">
            <div id="gatesale-confirm-step">
                <div class="setup-title">Is this you?</div>
                <div id="gatesale-confirm-details" style="text-align:left; margin-bottom: 20px; color:#333; line-height:1.6;"></div>
                <button class="setup-btn" style="background:#28a745; margin-bottom:10px;" onclick="gatesaleConfirmYes()">YES</button>
                <button class="setup-btn" style="background:#6c757d; margin-bottom:10px;" onclick="gatesaleShowEdit()">EDIT DETAILS</button>
                <button class="setup-btn" style="background:#dc3545;" onclick="gatesaleConfirmNo()">NO</button>
            </div>
            <div id="gatesale-edit-step" style="display:none;">
                <div class="setup-title">Edit Details</div>
                <input type="text" class="setup-input" id="gatesale-edit-name" placeholder="Full Name">
                <input type="email" class="setup-input" id="gatesale-edit-email" placeholder="Email">
                <input type="text" class="setup-input" id="gatesale-edit-phone" placeholder="Phone">
                <input type="text" class="setup-input" id="gatesale-edit-company" placeholder="Company">
                <input type="text" class="setup-input" id="gatesale-edit-plate-no" placeholder="Plate No." style="display:none;">
                <button class="setup-btn" onclick="gatesaleSaveEdit()">Save</button>
            </div>
            <div id="gatesale-visit-step" style="display:none;">
                <div class="setup-title">Visit Details</div>
                <p id="gatesale-visit-banner" style="display:none; color:#28a745; margin-bottom:15px;">Face registration successful. Please provide your visit details.</p>
                <input type="text" class="setup-input" id="gatesale-host-name" placeholder="Host Name">
                <input type="text" class="setup-input" id="gatesale-origin" placeholder="Origin">
                <input type="text" class="setup-input" id="gatesale-purpose" placeholder="Purpose">
                <button class="setup-btn" id="gatesale-visit-submit-btn" onclick="gatesaleSubmitVisit()">Continue</button>
            </div>
            <div id="gatesale-capture-step" style="display:none;">
                <div class="setup-title">Face Enrollment</div>
                <div style="position:relative; width:100%; max-width:700px; margin:0 auto 15px; border-radius:8px; overflow:hidden; background:#000;">
                    <video id="enrollment-webcam" autoplay playsinline muted style="width:100%; display:block; transform:scaleX(-1);"></video>
                </div>
                <p id="gatesale-capture-status" style="margin-bottom:12px; color:#333; min-height:24px;">Initializing...</p>
                <div style="display:flex; gap:8px; justify-content:center; margin-bottom:15px;">
                    <span id="gatesale-capture-dot-FRONT" style="width:12px;height:12px;border-radius:50%;background:#ccc;display:inline-block;"></span>
                    <span id="gatesale-capture-dot-LEFT" style="width:12px;height:12px;border-radius:50%;background:#ccc;display:inline-block;"></span>
                    <span id="gatesale-capture-dot-RIGHT" style="width:12px;height:12px;border-radius:50%;background:#ccc;display:inline-block;"></span>
                </div>
                <button class="setup-btn" style="background:#6c757d;" onclick="gatesaleCancelRegistration()">Cancel</button>
            </div>
            <div id="gatesale-register-step" style="display:none;">
                <div class="setup-title">Visitor Registration</div>
                <select class="setup-input" id="gatesale-reg-type" onchange="toggleGatesaleRegPlateNo()">
                    <option value="Gatesale" {{ $kiosk->facility?->is_gs ? '' : 'disabled' }}>Gatesale{{ $kiosk->facility?->is_gs ? '' : ' (not available at this facility)' }}</option>
                    <option value="Truck" {{ $kiosk->facility?->is_truck ? '' : 'disabled' }}>Truck / Delivery{{ $kiosk->facility?->is_truck ? '' : ' (not available at this facility)' }}</option>
                </select>
                <input type="text" class="setup-input" id="gatesale-reg-name" placeholder="Full Name">
                <input type="email" class="setup-input" id="gatesale-reg-email" placeholder="Email (optional)">
                <input type="text" class="setup-input" id="gatesale-reg-phone" placeholder="Phone (optional)">
                <input type="text" class="setup-input" id="gatesale-reg-company" placeholder="Company">
                <input type="text" class="setup-input" id="gatesale-reg-plate-no" placeholder="Plate No." style="display:none;">
                <button class="setup-btn" style="margin-bottom:10px;" onclick="submitGatesaleRegistration()">Save</button>
                <button class="setup-btn" style="background:#6c757d;" onclick="gatesaleCancelRegistration()">Cancel</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <script src="{{ asset('js/face-enrollment.js') }}"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    <script>
        const kioskId = '{{ $kiosk->kiosk_id }}';
        // facility_list.is_gs / facility_list.is_truck - whether Gatesale /
        // Truck self-service is allowed at this kiosk's facility. Each
        // drives the disabled attribute on its own <option> above; the
        // backend independently re-checks both on register-identity/create-
        // visit regardless of what the client sends.
        const gatesaleEnabled = {{ $kiosk->facility?->is_gs ? 'true' : 'false' }};
        const truckEnabled = {{ $kiosk->facility?->is_truck ? 'true' : 'false' }};
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
            ['setup-view', 'main-view', 'unauthorized-view', 'gatesale-view'].forEach(v => {
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

        // breakEnabled reflects facility_list.is_break_enabled for the
        // current visit's facility (sent once, at recognize time, as
        // currentVisitorRequest.break_enabled - not part of session_state
        // since it's a static-per-visit fact, not something that changes
        // across IN/OUT transitions). When false, the intermediate "Go
        // Outside" option is never rendered while Inside - only "Leave
        // Farm" - so the visitation is strictly one IN -> one OUT. This is
        // a UX convenience only; the real enforcement is the
        // temporary_exit guard in VisitorKioskService::processEntry().
        function showActionButtons(state, breakEnabled) {
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
                if (breakEnabled) {
                    buttonsHtml.push('<button class="btn btn-warning" onclick="processAction(\'temporary_exit\')">🚪 Go Outside</button>');
                }
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
                    showActionButtons(currentVisitorRequest.session_state, currentVisitorRequest.break_enabled);

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
            clearGatesaleIdleTimer();
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
                showActionButtons(result.session_state, result.break_enabled);
                updateAuthToggle();
                return;
            }

            if (result.type === 'request_completed') {
                updateStatus(result.message, 'error');
                showActionButtons(null);
                setTimeout(() => finishInteraction(), 3000);
                return;
            }

            if (result.type === 'gatesale_active_elsewhere') {
                updateStatus(result.message, 'error');
                showActionButtons(null);
                setTimeout(() => finishInteraction(), 3000);
                return;
            }

            if (['employee_detected', 'truck_detected', 'identity_not_supported', 'visitor_type_not_supported'].includes(result.type)) {
                updateStatus(result.message, 'info');
                showActionButtons(null);
                setTimeout(() => finishInteraction(), 3000);
                return;
            }

            if (result.type === 'gatesale_confirm_identity') {
                currentState = STATES.PROCESSING; // pause the background detection loop while this multi-step flow is open
                showGatesaleConfirm(result.directory);
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

        // Registration entry point for an unrecognized face - Gatesale only
        // in this phase (Truck registration is not implemented yet).
        function showRegisterVisitorPlaceholder() {
            document.getElementById('action-buttons').innerHTML =
                '<button class="btn btn-secondary" onclick="startGatesaleRegistration()">Registration</button>';
        }

        // ===== Gatesale flow =====

        let pendingGatesaleDirectory = null;
        let capturedGatesalePoses = null;
        let gatesaleEnrollmentController = null;
        let gatesaleIdleTimer = null;
        const GATESALE_IDLE_TIMEOUT_MS = 30000;

        // The Gatesale confirm/edit/visit-details flow waits indefinitely on
        // touch input, unlike every other PROCESSING use in this file (which
        // is always transient). Without this, a visitor walking away mid-flow
        // would strand the kiosk in PROCESSING forever.
        function startGatesaleIdleTimer() {
            clearGatesaleIdleTimer();
            gatesaleIdleTimer = setTimeout(() => {
                stopGatesaleEnrollment();
                showView('main-view');
                finishInteraction();
            }, GATESALE_IDLE_TIMEOUT_MS);
        }

        function clearGatesaleIdleTimer() {
            if (gatesaleIdleTimer) {
                clearTimeout(gatesaleIdleTimer);
                gatesaleIdleTimer = null;
            }
        }

        function hideAllGatesaleSteps() {
            ['gatesale-confirm-step', 'gatesale-edit-step', 'gatesale-visit-step', 'gatesale-capture-step', 'gatesale-register-step'].forEach(id => {
                document.getElementById(id).style.display = 'none';
            });
        }

        function showGatesaleConfirm(directory) {
            pendingGatesaleDirectory = directory;

            const lines = [`<strong>Name:</strong> ${directory.full_name}`];
            if (directory.phone) lines.push(`<strong>Phone:</strong> ${directory.phone}`);
            if (directory.email) lines.push(`<strong>Email:</strong> ${directory.email}`);
            if (directory.company) lines.push(`<strong>Company:</strong> ${directory.company}`);
            if (directory.plate_no) lines.push(`<strong>Plate No.:</strong> ${directory.plate_no}`);
            document.getElementById('gatesale-confirm-details').innerHTML = lines.join('<br>');

            hideAllGatesaleSteps();
            document.getElementById('gatesale-confirm-step').style.display = 'block';

            showView('gatesale-view');
            startGatesaleIdleTimer();
        }

        // Purely client-side, zero fetch calls - the matched person's
        // records must never be touched when identity isn't confirmed.
        function gatesaleConfirmNo() {
            clearGatesaleIdleTimer();
            pendingGatesaleDirectory = null;
            showView('main-view');
            updateStatus('Identity could not be confirmed. Please contact the administrator.', 'error');
            setTimeout(() => finishInteraction(), 3000);
        }

        function gatesaleShowEdit() {
            document.getElementById('gatesale-edit-name').value = pendingGatesaleDirectory.full_name || '';
            document.getElementById('gatesale-edit-email').value = pendingGatesaleDirectory.email || '';
            document.getElementById('gatesale-edit-phone').value = pendingGatesaleDirectory.phone || '';
            document.getElementById('gatesale-edit-company').value = pendingGatesaleDirectory.company || '';

            const isTruck = pendingGatesaleDirectory.visitor_type === 'Truck';
            const plateNoField = document.getElementById('gatesale-edit-plate-no');
            plateNoField.value = pendingGatesaleDirectory.plate_no || '';
            plateNoField.style.display = isTruck ? 'block' : 'none';

            hideAllGatesaleSteps();
            document.getElementById('gatesale-edit-step').style.display = 'block';
            startGatesaleIdleTimer();
        }

        // Never creates a request or session by itself - always returns to
        // "Is this you?" afterward, requiring an explicit YES.
        async function gatesaleSaveEdit() {
            const payload = {
                directory_id: pendingGatesaleDirectory.directory_id,
                full_name: document.getElementById('gatesale-edit-name').value.trim(),
                email: document.getElementById('gatesale-edit-email').value.trim(),
                phone: document.getElementById('gatesale-edit-phone').value.trim(),
                company: document.getElementById('gatesale-edit-company').value.trim(),
            };

            if (pendingGatesaleDirectory.visitor_type === 'Truck') {
                payload.plate_no = document.getElementById('gatesale-edit-plate-no').value.trim();
            }

            try {
                const response = await fetch(`/kiosk/${kioskId}/gatesale/update-details`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-KIOSK-TOKEN': kioskToken,
                    },
                    body: JSON.stringify(payload),
                });
                const result = await response.json();

                if (result.success) {
                    showGatesaleConfirm(result.directory);
                } else {
                    alert(result.message || 'Could not save details. Please try again.');
                }
            } catch (err) {
                console.error('Gatesale update-details error:', err);
                alert('Could not save details. Please try again.');
            }
        }

        // An ACTIVE request is never reachable from here anymore -
        // handleGatesaleRecognition() resolves that case before
        // gatesale_confirm_identity is ever returned - so YES always means
        // "collect Host/Origin/Purpose for a brand new visit."
        function gatesaleConfirmYes() {
            clearGatesaleIdleTimer();
            showGatesaleVisitStep(false);
        }

        function showGatesaleVisitStep(showBanner) {
            hideAllGatesaleSteps();
            document.getElementById('gatesale-host-name').value = '';
            document.getElementById('gatesale-origin').value = '';
            // Gatesale visitors are usually here to pick up eggs - a
            // sensible, editable default rather than an empty field. Truck
            // visits have no typical purpose, so it's left blank.
            document.getElementById('gatesale-purpose').value =
                pendingGatesaleDirectory.visitor_type === 'Truck' ? '' : 'Pickup Eggs';
            document.getElementById('gatesale-visit-banner').style.display = showBanner ? 'block' : 'none';
            document.getElementById('gatesale-visit-step').style.display = 'block';
            showView('gatesale-view');
            startGatesaleIdleTimer();
        }

        function gatesaleSubmitVisit() {
            const hostName = document.getElementById('gatesale-host-name').value.trim();
            const origin = document.getElementById('gatesale-origin').value.trim();
            const purpose = document.getElementById('gatesale-purpose').value.trim();

            if (!hostName || !origin || !purpose) {
                alert('Host Name, Origin, and Purpose are all required.');
                return;
            }

            submitGatesaleCreateVisit({ host_name: hostName, origin: origin, purpose: purpose });
        }

        async function submitGatesaleCreateVisit(extra) {
            clearGatesaleIdleTimer();
            const btn = document.getElementById('gatesale-visit-submit-btn');
            if (btn) btn.disabled = true;

            try {
                // Same capture used by the existing processAction() flow -
                // the video element keeps streaming even while #main-view
                // is hidden behind #gatesale-view, so this works regardless
                // of which view is currently showing.
                const video = document.getElementById('webcam');
                let photoBase64 = null;
                if (video && video.videoWidth) {
                    const canvas = document.createElement('canvas');
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    canvas.getContext('2d').drawImage(video, 0, 0);
                    photoBase64 = canvas.toDataURL('image/jpeg');
                }

                const response = await fetch(`/kiosk/${kioskId}/gatesale/create-visit`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-KIOSK-TOKEN': kioskToken,
                    },
                    body: JSON.stringify(Object.assign({ directory_id: pendingGatesaleDirectory.directory_id, photo: photoBase64 }, extra)),
                });

                showView('main-view');
                // Response shape matches a normal /recognize success - reuse
                // the existing handler rather than duplicating its logic
                // (currentVisitorRequest, modeBeforeDetection, etc).
                await handleRecognitionResponse(response, 'FACE');
                if (currentState === STATES.PROCESSING) {
                    // handleRecognitionResponse's generic failure branch only
                    // ever runs today from IDLE, so it doesn't recover
                    // PROCESSING on its own - force recovery here so a
                    // create-visit failure can never strand the kiosk.
                    finishInteraction();
                }
            } catch (err) {
                console.error('Gatesale create-visit error:', err);
                showView('main-view');
                finishInteraction();
                updateStatus('Something went wrong. Please try again.', 'error');
            } finally {
                if (btn) btn.disabled = false;
            }
        }

        // New-face Gatesale registration - runs the shared guided
        // FRONT/LEFT/RIGHT capture (public/js/face-enrollment.js) against a
        // dedicated #enrollment-webcam element fed by the SAME already-open
        // MediaStream as the main recognition loop's #webcam - no second
        // getUserMedia() call, so there's no camera-lock conflict, and the
        // continuous recognition loop itself (still targeting #webcam) is
        // left completely untouched. The main loop is paused for the
        // duration the same way every other multi-step Gatesale flow
        // already pauses it: currentState = STATES.PROCESSING.
        const GATESALE_CAPTURE_MESSAGES = {
            IDLE: 'Initializing...',
            INITIALIZING_CAMERA: 'Loading face recognition models...',
            DETECTING_FACE: {
                NO_FACE: 'No face detected. Please look at the camera.',
                MULTIPLE_FACES: 'Please ensure only one face is visible.',
                LOW_CONFIDENCE: 'Face not clear. Please improve lighting.',
                default: 'Looking for your face...',
            },
            POSITIONING: {
                TOO_FAR: 'Move closer to the camera.',
                TOO_CLOSE: 'Move back a little.',
                OFF_CENTER: 'Please center your face in the frame.',
                default: 'Position your face in the frame.',
            },
            FRONT_READY: { WRONG_POSE: 'Look straight at the camera.', default: 'Hold still...' },
            LEFT_READY: { WRONG_POSE: 'Slowly turn your head left.', default: 'Hold still...' },
            RIGHT_READY: { WRONG_POSE: 'Slowly turn your head right.', default: 'Hold still...' },
            FRONT_CAPTURED: 'Front captured!',
            LEFT_CAPTURED: 'Left captured!',
            RIGHT_CAPTURED: 'Right captured!',
            PROCESSING: 'Finishing up...',
            DUPLICATE_CHECK: 'Checking for an existing profile...',
            COMPLETE: 'Enrollment captured.',
            PROCESSING_ERROR: 'Something went wrong with face detection. Please try again.',
        };

        function gatesaleCaptureStatusFor(state, meta) {
            const entry = GATESALE_CAPTURE_MESSAGES[state];
            if (!entry) return 'Working...';
            if (typeof entry === 'string') return entry;
            return entry[(meta && meta.reason) || 'default'] || entry.default;
        }

        function onGatesaleEnrollmentStateChange(state, meta) {
            document.getElementById('gatesale-capture-status').textContent = gatesaleCaptureStatusFor(state, meta);

            const capturedIndex = { FRONT_CAPTURED: 0, LEFT_CAPTURED: 1, RIGHT_CAPTURED: 2 }[state];
            const activePose = meta && meta.pose;
            ['FRONT', 'LEFT', 'RIGHT'].forEach((pose, index) => {
                const dot = document.getElementById('gatesale-capture-dot-' + pose);
                dot.style.background = (capturedIndex !== undefined && index <= capturedIndex)
                    ? '#28a745'
                    : (activePose === pose ? '#667eea' : '#ccc');
            });

            // Reset the 30s abandonment timer on genuine progress (a face is
            // actually present/being evaluated) but NOT on every idle
            // no-face tick, so a truly abandoned kiosk mid-registration
            // still times out as intended.
            if (state !== 'DETECTING_FACE') {
                startGatesaleIdleTimer();
            }
        }

        function stopGatesaleEnrollment() {
            if (gatesaleEnrollmentController) {
                gatesaleEnrollmentController.stop();
                gatesaleEnrollmentController = null;
            }
        }

        function startGatesaleRegistration() {
            currentState = STATES.PROCESSING;
            capturedGatesalePoses = null;

            hideAllGatesaleSteps();
            document.getElementById('gatesale-capture-step').style.display = 'block';
            document.getElementById('gatesale-capture-status').textContent = 'Initializing...';
            ['FRONT', 'LEFT', 'RIGHT'].forEach(pose => {
                document.getElementById('gatesale-capture-dot-' + pose).style.background = '#ccc';
            });

            showView('gatesale-view');
            startGatesaleIdleTimer();

            // Same underlying camera stream the main recognition loop uses -
            // just fed into this screen's own <video> element, never a
            // second getUserMedia() acquisition.
            document.getElementById('enrollment-webcam').srcObject = stream;

            gatesaleEnrollmentController = FaceEnrollment.start({
                videoEl: document.getElementById('enrollment-webcam'),
                onStateChange: onGatesaleEnrollmentStateChange,
                onComplete: onGatesaleEnrollmentComplete,
                onError: (err) => {
                    console.error('Gatesale face enrollment error:', err);
                },
            });
        }

        // Guided capture finished (FRONT+LEFT+RIGHT all obtained) - move on
        // to the identity-details form, same as the old single-shot flow
        // did immediately after its one descriptor capture.
        function onGatesaleEnrollmentComplete(poses) {
            gatesaleEnrollmentController = null;
            capturedGatesalePoses = poses;

            // Default to whichever type is actually selectable - Gatesale
            // first, then Truck, per the gatesaleEnabled/truckEnabled flags.
            // If neither is enabled for this facility the select is left on
            // its natural default (both options disabled); submit is
            // rejected client-side either way (see submitGatesaleRegistration).
            if (gatesaleEnabled) {
                document.getElementById('gatesale-reg-type').value = 'Gatesale';
            } else if (truckEnabled) {
                document.getElementById('gatesale-reg-type').value = 'Truck';
            }
            ['gatesale-reg-name', 'gatesale-reg-email', 'gatesale-reg-phone', 'gatesale-reg-company', 'gatesale-reg-plate-no'].forEach(id => {
                document.getElementById(id).value = '';
            });
            toggleGatesaleRegPlateNo();

            hideAllGatesaleSteps();
            document.getElementById('gatesale-register-step').style.display = 'block';
            startGatesaleIdleTimer();
        }

        function toggleGatesaleRegPlateNo() {
            const isTruck = document.getElementById('gatesale-reg-type').value === 'Truck';
            document.getElementById('gatesale-reg-plate-no').style.display = isTruck ? 'block' : 'none';
        }

        function gatesaleCancelRegistration() {
            clearGatesaleIdleTimer();
            stopGatesaleEnrollment();
            capturedGatesalePoses = null;
            showView('main-view');
            finishInteraction();
        }

        async function submitGatesaleRegistration() {
            const visitorType = document.getElementById('gatesale-reg-type').value;

            const fullName = document.getElementById('gatesale-reg-name').value.trim();
            const company = document.getElementById('gatesale-reg-company').value.trim();
            const email = document.getElementById('gatesale-reg-email').value.trim();
            const phone = document.getElementById('gatesale-reg-phone').value.trim();
            const plateNo = document.getElementById('gatesale-reg-plate-no').value.trim();

            if (!fullName || !company) {
                alert('Full Name and Company are required.');
                return;
            }

            if (visitorType === 'Gatesale' && !gatesaleEnabled) {
                // Should be unreachable via the UI (the option is disabled),
                // but the backend rejects this anyway - this is just a
                // clearer message than letting the fetch below 403 silently.
                alert('Gatesale self-service is not available at this facility.');
                return;
            }

            if (visitorType === 'Truck' && !truckEnabled) {
                alert('Truck self-service is not available at this facility.');
                return;
            }

            if (visitorType === 'Truck' && !plateNo) {
                alert('Plate No. is required for Truck registration.');
                return;
            }

            clearGatesaleIdleTimer();

            try {
                const response = await fetch(`/kiosk/${kioskId}/gatesale/register-identity`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-KIOSK-TOKEN': kioskToken,
                    },
                    body: JSON.stringify({
                        visitor_type: visitorType,
                        full_name: fullName,
                        company: company,
                        email: email,
                        phone: phone,
                        plate_no: plateNo,
                        // Whichever poses the guided capture actually
                        // obtained (FRONT/LEFT/RIGHT) - never fabricated.
                        poses: capturedGatesalePoses,
                    }),
                });
                const result = await response.json();

                if (result.success) {
                    // Identity registered - no visitor_request yet. Go
                    // straight to Visit Details (Host/Origin/Purpose), the
                    // visitor has already explicitly identified themselves.
                    pendingGatesaleDirectory = result.directory;
                    showGatesaleVisitStep(true);
                    return;
                }

                if (result.type === 'gatesale_confirm_identity') {
                    // This face actually already exists - route through the
                    // normal identity-confirmation flow instead.
                    showGatesaleConfirm(result.directory);
                    return;
                }

                showView('main-view');
                finishInteraction();
                updateStatus(result.message || 'Identity could not be confirmed. Please contact the administrator.', 'error');
            } catch (err) {
                console.error('Gatesale registration error:', err);
                alert('Something went wrong. Please try again.');
            }
        }

        initialize();
    </script>
</body>
</html>
