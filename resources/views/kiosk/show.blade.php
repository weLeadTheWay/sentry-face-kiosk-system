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

    <script>
        const kioskId = '{{ $kiosk->kiosk_id }}';
        let kioskToken = localStorage.getItem('kiosk_token_' + kioskId);
        let currentVisitorRequest = null;
        let isProcessingAction = false;
        let stream = null;

        // State machine for kiosk flow
        const STATES = {
            IDLE: 'IDLE',                    // Ready to recognize faces
            DETECTED: 'DETECTED',            // Face matched, showing action buttons
            PROCESSING: 'PROCESSING',        // Processing an action (entry/exit)
        };
        let currentState = STATES.IDLE;
        let lastRecognizedDirectoryId = null;

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

        async function initialize() {
            if (!kioskToken) {
                document.getElementById('setup-view').style.display = 'flex';
                document.getElementById('main-view').style.display = 'none';
                return;
            }

            document.getElementById('setup-view').style.display = 'none';
            document.getElementById('main-view').style.display = 'flex';

            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } }
                });
                document.getElementById('webcam').srcObject = stream;
                document.getElementById('status-message').textContent = 'Ready for face recognition...';
            } catch (err) {
                updateStatus('Camera access denied', 'error');
            }
        }

        function updateStatus(message, type = 'info') {
            const el = document.getElementById('status-message');
            el.textContent = message;
            el.className = 'status-message ' + (type === 'error' ? 'error' : type === 'success' ? 'welcome' : '');
        }

        function showActionButtons(state) {
            const buttonsHtml = [];

            if (!state || state.status === 'no_session') {
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

                    // After action completes, reset to IDLE for next recognition
                    setTimeout(() => {
                        resetToIdle();
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

        function resetToIdle() {
            currentState = STATES.IDLE;
            lastRecognizedDirectoryId = null;
            currentVisitorRequest = null;
            updateStatus('Scan your face...', 'info');
            showActionButtons(null);
        }

        async function attemptFaceRecognition() {
            // Only recognize when in IDLE state, not while processing or when face already detected
            if (!stream || currentState !== STATES.IDLE) {
                return;
            }

            try {
                const video = document.getElementById('webcam');
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0);

                const response = await fetch(`/kiosk/${kioskId}/recognize`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-KIOSK-TOKEN': kioskToken,
                    },
                    body: JSON.stringify({
                        descriptor: [0.1, 0.2, 0.3],
                    }),
                });

                if (!response.ok) {
                    return;
                }

                const result = await response.json();
                if (result.success) {
                    // Prevent re-recognizing the same person multiple times
                    if (lastRecognizedDirectoryId === result.directory.directory_id) {
                        return;
                    }

                    // Update state and data
                    currentState = STATES.DETECTED;
                    lastRecognizedDirectoryId = result.directory.directory_id;
                    currentVisitorRequest = result;
                    updateStatus(`Welcome, ${result.directory.full_name}!`, 'success');
                    showActionButtons(result.session_state);
                } else {
                    // No match, stay in IDLE state
                    updateStatus('Scan your face...', 'info');
                }
            } catch (err) {
                console.error('Recognition error:', err);
            }
        }

        setInterval(attemptFaceRecognition, 2000);
        initialize();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
</body>
</html>
