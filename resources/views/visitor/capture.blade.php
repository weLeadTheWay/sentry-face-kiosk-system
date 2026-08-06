<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Face Registration - {{ config('app.name') }}</title>
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
        .video-container {
            position: relative;
            width: 100%;
            max-width: 400px;
            margin: 0 auto 20px;
            background: #000;
            border-radius: 8px;
            overflow: hidden;
        }
        video {
            width: 100%;
            display: block;
            transform: scaleX(-1); /* mirror the preview so it feels like a normal front-facing camera */
        }
        .controls {
            text-align: center;
            margin-bottom: 20px;
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
            margin: 5px;
        }
        .btn:hover {
            background: #5568d3;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .status {
            text-align: center;
            padding: 15px;
            background: #f0f0f0;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 14px;
            min-height: 40px;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="title">Face Registration</div>
            <div class="status" id="status">Initializing camera...</div>

            <div class="video-container">
                <video id="webcam" autoplay playsinline></video>
            </div>

            <div class="controls" id="capture-controls">
                <button class="btn" id="capture-btn" onclick="captureFrame()">📸 Capture Face</button>
                <button class="btn btn-secondary" onclick="window.history.back()">Cancel</button>
            </div>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Processing face...</p>
            </div>

            <div id="face-match-prompt" style="display: none; margin-top: 20px; text-align: center;">
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <p style="font-size: 14px; color: #666; margin: 0 0 10px 0;">Matched Profile:</p>
                    <p style="font-size: 18px; font-weight: 600; color: #333; margin: 0;" id="matched-name">
                        Loading...
                    </p>
                </div>
                <p style="font-size: 16px; margin-bottom: 20px; color: #333;">
                    <strong>Is this you?</strong>
                </p>
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <button class="btn" onclick="confirmFaceMatch(true)" style="background: #28a745;">Yes, It's Me</button>
                    <button class="btn" onclick="confirmFaceMatch(false)" style="background: #dc3545;">No, Different Person</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <script>
        const token = new URL(window.location).searchParams.get('token');
        let stream = null;
        let lastDirectoryId = null;
        let modelsLoaded = false;

        const MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
        const MIN_FACE_CONFIDENCE = 0.7;

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
                document.getElementById('status').textContent = 'Camera ready. Click "Capture Face" when ready.';
            } catch (err) {
                document.getElementById('status').textContent = 'Camera access denied. Please enable camera permissions.';
            }
        }

        async function captureFrame() {
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

            const detection = detections[0];

            if (detection.detection.score < MIN_FACE_CONFIDENCE) {
                document.getElementById('status').textContent = 'Face not clear, please try again in better lighting.';
                return;
            }

            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0);
            const imageData = canvas.toDataURL('image/jpeg');
            const descriptorArray = Array.from(detection.descriptor);

            registerFace(descriptorArray, imageData);
        }

        function registerFace(descriptorArray, imageData) {
            document.getElementById('loading').style.display = 'block';
            document.getElementById('status').textContent = 'Processing...';

            fetch('/register/visitor/capture', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({
                    token: token,
                    descriptor: descriptorArray,
                    face_image: imageData,
                }),
            })
            .then(res => res.json())
            .then(data => {
                document.getElementById('loading').style.display = 'none';

                if (data.status === 'face_found_different_directory') {
                    lastDirectoryId = data.directory_id;
                    document.getElementById('capture-controls').style.display = 'none';
                    document.getElementById('face-match-prompt').style.display = 'block';
                    document.getElementById('matched-name').textContent = data.matched_name || 'Unknown Person';
                    document.getElementById('status').textContent = 'Found existing face...';
                    return;
                }

                if (data.status === 'already_registered') {
                    document.getElementById('status').textContent = "You're already registered! Redirecting...";
                    document.getElementById('status').style.color = '#28a745';
                    document.getElementById('status').style.fontWeight = '600';
                    setTimeout(() => {
                        window.location.href = '/register/visitor/success?token=' + token;
                    }, 1500);
                    return;
                }

                if (data.success) {
                    window.location.href = '/register/visitor/success?token=' + token;
                } else {
                    document.getElementById('status').textContent = data.message || 'Error processing face';
                }
            })
            .catch(err => {
                document.getElementById('loading').style.display = 'none';
                document.getElementById('status').textContent = 'Error: ' + err.message;
            });
        }

        // Both "Yes, It's Me" and "No, Different Person" always proceed to the
        // success page - success.blade.php renders the correct framing
        // (registered vs. biometric-conflict) based on face_registration_status.
        function confirmFaceMatch(isConfirmed) {
            document.getElementById('loading').style.display = 'block';
            document.getElementById('face-match-prompt').style.display = 'none';
            document.getElementById('status').textContent = 'Processing...';

            fetch('/register/visitor/confirm', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({
                    token: token,
                    directory_id: lastDirectoryId,
                    confirmed: isConfirmed,
                }),
            })
            .then(res => res.json())
            .then(data => {
                document.getElementById('loading').style.display = 'none';
                if (data.success) {
                    window.location.href = '/register/visitor/success?token=' + token;
                } else {
                    document.getElementById('status').textContent = data.message || 'Error processing';
                }
            })
            .catch(err => {
                document.getElementById('loading').style.display = 'none';
                document.getElementById('status').textContent = 'Error: ' + err.message;
            });
        }

        initCamera();
    </script>
</body>
</html>
