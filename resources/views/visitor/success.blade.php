<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Registration Complete - {{ config('app.name') }}</title>
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
            text-align: center;
        }
        .success-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        .title {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #28a745;
        }
        .subtitle {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
        }
        .qr-container {
            padding: 30px;
            background: #f9f9f9;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .qr-code {
            max-width: 300px;
            margin: 0 auto 20px;
            background: white;
            padding: 10px;
            border-radius: 4px;
        }
        .qr-code img {
            width: 100%;
            height: auto;
        }
        .qr-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        .visitor-id {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
            word-break: break-all;
        }
        .instructions {
            background: #e7f3ff;
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: left;
            border-left: 4px solid #667eea;
        }
        .instructions h3 {
            font-size: 16px;
            margin-bottom: 10px;
            color: #333;
        }
        .instructions ol {
            margin-left: 20px;
            color: #666;
            font-size: 14px;
        }
        .instructions li {
            margin-bottom: 8px;
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
            text-decoration: none;
            margin: 5px;
        }
        .btn:hover {
            background: #5568d3;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        .warning-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        .warning-title {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #856404;
        }
        .warning-box {
            background: #fff3cd;
            color: #856404;
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 30px;
            border: 1px solid #ffeeba;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            @if(isset($error))
                <div class="error">{{ $error }}</div>
            @elseif(isset($visitorRequest))
                @if($visitorRequest->face_registration_status === 'FAILED_MATCH')
                    <div class="warning-icon">⚠️</div>
                    <div class="warning-title">Manual Verification Required</div>
                    <div class="warning-box">
                        A biometric conflict has been detected. Please contact the administrator. You may still use your assigned QR Code for entry.
                    </div>
                @else
                    <div class="success-icon">✓</div>
                    <div class="title">Registration Complete!</div>
                    <div class="subtitle">You're ready to visit {{ $visitorRequest->facility->facility_name }}</div>
                @endif

                <div class="qr-container">
                    <div class="qr-label">Your Visitor QR Code</div>
                    <div class="qr-code">
                        <img src="{{ route('visitor.qr', ['token' => $token]) }}" alt="Visitor QR Code">
                    </div>
                    <div class="visitor-id">ID: {{ $visitorRequest->visitor_id }}</div>
                    <button class="btn" style="margin-top: 10px; width: 100%;" onclick="downloadQR()">📥 Download QR Code</button>
                </div>

                <div class="instructions">
                    <h3>At the Kiosk:</h3>
                    <ol>
                        <li><strong>Face Recognition:</strong> Look at the camera for face recognition (3 attempts)</li>
                        <li><strong>QR Fallback:</strong> If face recognition fails, scan your QR code</li>
                        <li><strong>Entry Confirmation:</strong> System will confirm your entry/exit</li>
                        <li><strong>Completion:</strong> You'll see a confirmation on screen</li>
                    </ol>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 4px; border-left: 4px solid #ffc107;">
                    <p style="font-size: 14px; color: #856404;">
                        <strong>Save this page or download the QR code.</strong> You'll need it at the kiosk.
                    </p>
                </div>

                <div style="margin-top: 20px;">
                    <p style="font-size: 14px; color: #666; margin-bottom: 10px;">
                        Questions? Contact {{ $visitorRequest->host_name }}
                    </p>
                </div>
            @endif
        </div>
    </div>

    <script>
        function downloadQR() {
            const link = document.createElement('a');
            link.href = '{{ isset($visitorRequest) ? route("visitor.qr", ["token" => $token, "download" => 1]) : "#" }}';
            link.download = 'visitor-qr-{{ $visitorRequest->visitor_id ?? "code" }}.png';
            link.click();
        }
    </script>
</body>
</html>
