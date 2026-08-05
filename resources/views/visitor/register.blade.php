<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Visitor Registration - {{ config('app.name') }}</title>
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
        .logo {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 20px;
        }
        .title {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
        }
        .subtitle {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        .options {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .option-btn {
            flex: 1;
            padding: 30px 20px;
            border: 2px solid #ddd;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 16px;
        }
        .option-btn:hover {
            border-color: #667eea;
            background: #f9f9f9;
        }
        .option-btn.active {
            border-color: #667eea;
            background: #667eea;
            color: white;
        }
        .option-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        .option-desc {
            font-size: 14px;
            opacity: 0.7;
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
        .hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="logo">{{ config('app.name') }}</div>
            <div class="title">Visitor Registration</div>
            <div class="subtitle">Welcome {{ $visitorRequest->directory->full_name ?? 'Visitor' }}</div>

            @if(isset($error))
                <div class="error">{{ $error }}</div>
            @endif

            @if(isset($visitorRequest))
                <div style="margin-bottom: 30px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
                    <p style="font-size: 14px; color: #666;">Farm: <strong>{{ $visitorRequest->farm->farm_name }}</strong></p>
                    <p style="font-size: 14px; color: #666;">Host: <strong>{{ $visitorRequest->host_name }}</strong></p>
                </div>

                <div style="margin-bottom: 20px;">
                    <p style="margin-bottom: 15px; color: #666;">How would you like to register?</p>
                    <div class="options">
                        <button class="option-btn" onclick="selectOption('A')">
                            <div class="option-title">📸 Option A</div>
                            <div class="option-desc">Capture Face Now</div>
                        </button>
                        <button class="option-btn" onclick="selectOption('B')">
                            <div class="option-title">🔍 Option B</div>
                            <div class="option-desc">Find Previous Visit</div>
                        </button>
                    </div>
                </div>

                <div id="option-a" class="hidden">
                    <p style="margin-bottom: 20px; color: #666;">We'll capture your facial features for kiosk entry.</p>
                    <button class="btn" onclick="goToCaptureA()">Start Face Capture</button>
                </div>

                <div id="option-b" class="hidden">
                    <p style="margin-bottom: 20px; color: #666;">Search for a previous visit to link this request.</p>
                    <button class="btn" onclick="goToSearchB()">Search by Name</button>
                </div>
            @endif
        </div>
    </div>

    <script>
        let selectedOption = null;
        const token = new URL(window.location).searchParams.get('token');

        function selectOption(option) {
            selectedOption = option;
            document.querySelectorAll('.option-btn').forEach(btn => btn.classList.remove('active'));
            event.target.closest('.option-btn').classList.add('active');
            document.getElementById('option-a').classList.add('hidden');
            document.getElementById('option-b').classList.add('hidden');
            if (option === 'A') {
                document.getElementById('option-a').classList.remove('hidden');
            } else if (option === 'B') {
                document.getElementById('option-b').classList.remove('hidden');
            }
        }

        function goToCaptureA() {
            window.location.href = '/register/visitor/capture?token=' + token + '&option=A';
        }

        function goToSearchB() {
            window.location.href = '/register/visitor/search?token=' + token + '&option=B';
        }
    </script>
</body>
</html>
