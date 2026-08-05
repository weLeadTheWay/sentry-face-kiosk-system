<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        .results {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            display: none;
        }
        .result-item {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.2s;
        }
        .result-item:hover {
            background: #f9f9f9;
        }
        .result-name {
            font-weight: 500;
        }
        .result-email {
            font-size: 12px;
            color: #666;
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
        .controls button {
            flex: 1;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="title">Find Previous Visit</div>
            <p style="text-align: center; color: #666; margin-bottom: 20px;">Search by name to link to an existing directory</p>

            <div class="form-group">
                <label for="search">Search Name:</label>
                <input type="text" id="search" placeholder="Enter your name" autocomplete="off">
            </div>

            <div class="results" id="results"></div>

            <div class="controls">
                <button class="btn btn-secondary" onclick="window.history.back()">Back</button>
            </div>
        </div>
    </div>

    <script>
        const token = new URL(window.location).searchParams.get('token');
        const searchInput = document.getElementById('search');
        const resultsDiv = document.getElementById('results');

        searchInput.addEventListener('input', async function() {
            const query = this.value.trim();
            if (query.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }

            try {
                const response = await fetch('/register/visitor/search?q=' + encodeURIComponent(query));
                const data = await response.json();

                if (data.results && data.results.length > 0) {
                    resultsDiv.innerHTML = data.results.map(item => `
                        <div class="result-item" onclick="selectVisitor(${item.directory_id}, '${item.full_name}')">
                            <div class="result-name">${item.full_name}</div>
                            <div class="result-email">${item.email}</div>
                        </div>
                    `).join('');
                    resultsDiv.style.display = 'block';
                } else {
                    resultsDiv.innerHTML = '<div class="result-item">No results found</div>';
                    resultsDiv.style.display = 'block';
                }
            } catch (err) {
                console.error('Search error:', err);
            }
        });

        function selectVisitor(directoryId, fullName) {
            window.location.href = '/register/visitor/capture?token=' + token + '&option=B&directory_id=' + directoryId;
        }

        searchInput.focus();
    </script>
</body>
</html>
