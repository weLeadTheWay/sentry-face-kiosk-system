<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

            <div class="controls">
                <button class="btn" onclick="captureFrame()">📸 Capture Face</button>
                <button class="btn btn-secondary" onclick="window.history.back()">Cancel</button>
            </div>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Processing face...</p>
            </div>
        </div>
    </div>

    <script>
        const token = new URL(window.location).searchParams.get('token');
        const option = new URL(window.location).searchParams.get('option') || 'A';
        let stream = null;

        async function initCamera() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
                document.getElementById('webcam').srcObject = stream;
                document.getElementById('status').textContent = 'Camera ready. Click "Capture Face" when ready.';
            } catch (err) {
                document.getElementById('status').textContent = 'Camera access denied. Please enable camera permissions.';
            }
        }

        function captureFrame() {
            const video = document.getElementById('webcam');
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0);
            const imageData = canvas.toDataURL('image/jpeg');

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
                    descriptor: [0.1, 0.2, 0.3],
                    face_image: imageData,
                }),
            })
            .then(res => res.json())
            .then(data => {
                document.getElementById('loading').style.display = 'none';
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

        initCamera();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
</body>
</html>
