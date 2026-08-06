<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Find Previous Visit - {{ config('app.name') }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
        }
        .container {
            width: 100%;
            max-width: 600px;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 40px;
        }
        .title {
            font-size: 24px;
            font-weight: 600;
            text-align: center;
            margin-bottom: 20px;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .hint {
            font-size: 12px;
            color: #999;
            margin-top: 6px;
        }
        .search-status {
            font-size: 13px;
            color: #667eea;
            margin-top: 8px;
            display: none;
        }
        .results {
            max-height: 320px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            display: none;
            margin-top: 10px;
        }
        .result-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.2s;
        }
        .result-item:last-child {
            border-bottom: none;
        }
        .result-item:hover {
            background: #f5f6ff;
        }
        .result-avatar {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #667eea;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
        }
        .result-name {
            font-weight: 500;
        }
        .result-email {
            font-size: 12px;
            color: #666;
        }
        .empty-state {
            padding: 24px 14px;
            text-align: center;
            color: #999;
            font-size: 14px;
            cursor: default;
        }
        .empty-state:hover {
            background: none;
        }
        .video-container {
            position: relative;
            width: 100%;
            max-width: 360px;
            margin: 0 auto 20px;
            background: #000;
            border-radius: 8px;
            overflow: hidden;
        }
        video {
            width: 100%;
            display: block;
        }
        .status {
            text-align: center;
            padding: 15px;
            background: #f0f0f0;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 14px;
            min-height: 20px;
        }
        .loading {
            display: none;
            text-align: center;
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin-top: 10px;
        }
        .btn:hover {
            background: #5568d3;
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .controls {
            display: flex;
            gap: 10px;
        }
        .controls button, .controls a {
            flex: 1;
        }
        .back-link {
            display: block;
            text-align: center;
            font-size: 13px;
            color: #667eea;
            margin-top: 15px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            @if(isset($error))
                <div class="title">Find Previous Visit</div>
                <div class="error">{{ $error }}</div>
            @else
                {{-- Step 1: search --}}
                <div id="search-step">
                    <div class="title">Find Previous Visit</div>
                    <p style="text-align: center; color: #666; margin-bottom: 20px;">Search by name to link to an existing profile</p>

                    <div class="form-group">
                        <label for="search">Search Name:</label>
                        <input type="text" id="search" placeholder="Enter your name" autocomplete="off">
                        <div class="hint">Type at least 2 characters to search</div>
                        <div class="search-status" id="search-status">Searching...</div>
                    </div>

                    <div class="results" id="results"></div>

                    <div class="controls">
                        <button class="btn btn-secondary" onclick="window.history.back()">Back</button>
                    </div>
                </div>

                {{-- Step 2: inline face verification (no page navigation) --}}
                <div id="verify-step" style="display: none;">
                    <div class="title">Face Verification</div>
                    <p style="text-align: center; color: #666; margin-bottom: 15px;">Confirming as: <strong id="verify-name"></strong></p>

                    <div class="status" id="status">Initializing camera...</div>

                    <div class="video-container">
                        <video id="webcam" autoplay playsinline></video>
                    </div>

                    <div class="controls" id="verify-controls">
                        <button class="btn" id="verify-btn" onclick="captureAndVerify()">📸 Verify My Face</button>
                    </div>

                    <div class="loading" id="loading">
                        <div class="spinner"></div>
                        <p>Verifying...</p>
                    </div>

                    <span class="back-link" onclick="backToSearch()">‹ Not you? Back to search results</span>
                </div>
            @endif
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <script>
        const token = new URL(window.location).searchParams.get('token');
        const searchInput = document.getElementById('search');
        const resultsDiv = document.getElementById('results');
        const statusDiv = document.getElementById('search-status');
        let currentResults = [];
        let debounceTimer = null;
        let requestSeq = 0;

        let stream = null;
        let modelsLoaded = false;
        let verifyAttempts = 0;
        let selectedDirectoryId = null;
        const MAX_VERIFY_ATTEMPTS = 3;
        const MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
        const MIN_FACE_CONFIDENCE = 0.7;

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str ?? '';
            return div.innerHTML;
        }

        function initials(fullName) {
            return (fullName || '')
                .split(' ')
                .filter(Boolean)
                .slice(0, 2)
                .map(part => part[0].toUpperCase())
                .join('') || '?';
        }

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                clearTimeout(debounceTimer);

                if (query.length < 2) {
                    resultsDiv.style.display = 'none';
                    statusDiv.style.display = 'none';
                    return;
                }

                debounceTimer = setTimeout(() => runSearch(query), 300);
            });
            searchInput.focus();
        }

        async function runSearch(query) {
            const seq = ++requestSeq;
            statusDiv.style.display = 'block';

            try {
                const response = await fetch('/register/visitor/search/query?q=' + encodeURIComponent(query));
                const data = await response.json();

                if (seq !== requestSeq) {
                    return;
                }

                statusDiv.style.display = 'none';
                currentResults = data.results || [];

                if (currentResults.length > 0) {
                    resultsDiv.innerHTML = currentResults.map((item, index) => `
                        <div class="result-item" onclick="selectVisitor(${index})">
                            <div class="result-avatar">${escapeHtml(initials(item.full_name))}</div>
                            <div>
                                <div class="result-name">${escapeHtml(item.full_name)}</div>
                                <div class="result-email">${escapeHtml(item.email)}</div>
                            </div>
                        </div>
                    `).join('');
                } else {
                    resultsDiv.innerHTML = '<div class="result-item empty-state">No matching visitors found.<br>Try a different spelling, or go back and register a new face instead.</div>';
                }
                resultsDiv.style.display = 'block';
            } catch (err) {
                if (seq !== requestSeq) {
                    return;
                }
                statusDiv.style.display = 'none';
                resultsDiv.innerHTML = '<div class="result-item empty-state">Search failed. Please check your connection and try again.</div>';
                resultsDiv.style.display = 'block';
                console.error('Search error:', err);
            }
        }

        function selectVisitor(index) {
            const item = currentResults[index];
            if (!item) {
                return;
            }
            selectedDirectoryId = item.directory_id;
            verifyAttempts = 0;
            document.getElementById('verify-name').textContent = item.full_name;
            document.getElementById('search-step').style.display = 'none';
            document.getElementById('verify-step').style.display = 'block';
            document.getElementById('verify-btn').disabled = false;

            if (!stream) {
                initCamera();
            }
        }

        function backToSearch() {
            document.getElementById('verify-step').style.display = 'none';
            document.getElementById('search-step').style.display = 'block';
            document.getElementById('status').textContent = '';
        }

        async function loadModels() {
            document.getElementById('status').textContent = 'Loading face recognition models...';
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
            ]);
            modelsLoaded = true;
        }

        async function initCamera() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
                document.getElementById('webcam').srcObject = stream;
                await loadModels();
                document.getElementById('status').textContent = 'Camera ready. Click "Verify My Face" when ready.';
            } catch (err) {
                document.getElementById('status').textContent = 'Camera access denied. Please enable camera permissions.';
            }
        }

        async function captureAndVerify() {
            if (!modelsLoaded) {
                document.getElementById('status').textContent = 'Face models still loading, please wait...';
                return;
            }

            const video = document.getElementById('webcam');
            document.getElementById('status').textContent = 'Detecting face...';

            const detections = await faceapi
                .detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 }))
                .withFaceLandmarks()
                .withFaceDescriptors();

            if (detections.length === 0) {
                document.getElementById('status').textContent = 'No face detected. Please center your face in the camera and try again.';
                return;
            }

            if (detections.length > 1) {
                document.getElementById('status').textContent = 'Please ensure only one face is visible.';
                return;
            }

            if (detections[0].detection.score < MIN_FACE_CONFIDENCE) {
                document.getElementById('status').textContent = 'Face not clear, please try again in better lighting.';
                return;
            }

            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0);
            const imageData = canvas.toDataURL('image/jpeg');
            const descriptorArray = Array.from(detections[0].descriptor);

            verifyAttempts++;

            document.getElementById('loading').style.display = 'block';
            document.getElementById('status').textContent = 'Verifying...';

            fetch('/register/visitor/verify', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({
                    token: token,
                    directory_id: selectedDirectoryId,
                    descriptor: descriptorArray,
                    face_image: imageData,
                    attempt: verifyAttempts,
                }),
            })
            .then(res => res.json())
            .then(data => {
                document.getElementById('loading').style.display = 'none';

                if (data.status === 'success') {
                    document.getElementById('status').textContent = 'Verified! Redirecting...';
                    window.location.href = '/register/visitor/success?token=' + encodeURIComponent(token);
                    return;
                }

                if (data.status === 'verification_failed') {
                    document.getElementById('status').textContent = data.message;
                    document.getElementById('verify-btn').disabled = true;
                    setTimeout(() => {
                        window.location.href = '/register/visitor/success?token=' + encodeURIComponent(token);
                    }, 2500);
                    return;
                }

                const remaining = MAX_VERIFY_ATTEMPTS - verifyAttempts;
                document.getElementById('status').textContent = (data.message || 'Face did not match.') + (remaining > 0 ? ` (${remaining} attempt${remaining === 1 ? '' : 's'} remaining)` : '');
            })
            .catch(err => {
                document.getElementById('loading').style.display = 'none';
                document.getElementById('status').textContent = 'Error: ' + err.message;
            });
        }
    </script>
</body>
</html>
