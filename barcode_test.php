<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barcode Scanner Test</title>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .test-container {
            margin: 20px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: #f8f9fa;
        }
        
        button {
            padding: 10px 15px;
            background-color: #4e73df;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px;
        }
        
        button:hover {
            background-color: #2e59d9;
        }
        
        #scanner-container {
            margin-top: 20px;
        }
        
        #qr-reader {
            width: 400px;
            max-width: 100%;
            margin: 0 auto;
        }
        
        .result {
            margin-top: 20px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #e8f4f8;
        }
        
        #log {
            height: 150px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            background-color: #f5f5f5;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <h1>Barcode Scanner Test Page</h1>
    <p>This page will help diagnose issues with the barcode scanning functionality.</p>
    
    <div class="test-container">
        <h2>1. Camera Access Test</h2>
        <p>First, let's check if your browser can access the camera:</p>
        <button id="testCamera">Test Camera Access</button>
        <div id="cameraResult"></div>
    </div>
    
    <div class="test-container">
        <h2>2. Barcode Scanner Test</h2>
        <p>Now let's test if we can scan barcodes:</p>
        <button id="startScanner">Start Scanner</button>
        <button id="stopScanner" style="display: none;">Stop Scanner</button>
        
        <div id="scanner-container">
            <div id="qr-reader"></div>
        </div>
        
        <div class="result">
            <h3>Scan Result:</h3>
            <div id="scanResult">No code scanned yet</div>
        </div>
    </div>
    
    <div class="test-container">
        <h2>Debug Log</h2>
        <div id="log"></div>
    </div>
    
    <script>
        // Logging function
        function logMessage(message) {
            const logElement = document.getElementById('log');
            const timestamp = new Date().toLocaleTimeString();
            logElement.innerHTML += `<div>[${timestamp}] ${message}</div>`;
            logElement.scrollTop = logElement.scrollHeight;
        }
        
        // Camera test
        document.getElementById('testCamera').addEventListener('click', function() {
            const resultElement = document.getElementById('cameraResult');
            resultElement.innerHTML = 'Testing camera access...';
            
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                resultElement.innerHTML = '<span style="color: red;">ERROR: Your browser does not support camera access</span>';
                logMessage('ERROR: Browser does not support mediaDevices.getUserMedia');
                return;
            }
            
            navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(stream) {
                    resultElement.innerHTML = '<span style="color: green;">SUCCESS: Camera access granted!</span>';
                    logMessage('SUCCESS: Camera access test passed');
                    
                    // Stop all tracks to release the camera
                    stream.getTracks().forEach(track => track.stop());
                })
                .catch(function(error) {
                    resultElement.innerHTML = `<span style="color: red;">ERROR: ${error.name} - ${error.message}</span>`;
                    logMessage(`ERROR: Camera access failed - ${error.name}: ${error.message}`);
                });
        });
        
        // Barcode scanner
        let html5QrcodeScanner = null;
        
        document.getElementById('startScanner').addEventListener('click', function() {
            const startButton = document.getElementById('startScanner');
            const stopButton = document.getElementById('stopScanner');
            const scannerContainer = document.getElementById('qr-reader');
            
            startButton.style.display = 'none';
            stopButton.style.display = 'inline-block';
            
            logMessage('Starting barcode scanner...');
            scannerContainer.innerHTML = '<div style="text-align: center; padding: 20px;">Initializing scanner...</div>';
            
            // Create scanner instance
            try {
                html5QrcodeScanner = new Html5Qrcode("qr-reader");
                
                // Define supported formats
                const formatsToSupport = [
                    Html5QrcodeSupportedFormats.QR_CODE,
                    Html5QrcodeSupportedFormats.CODE_39,
                    Html5QrcodeSupportedFormats.CODE_93,
                    Html5QrcodeSupportedFormats.CODE_128,
                    Html5QrcodeSupportedFormats.EAN_8,
                    Html5QrcodeSupportedFormats.EAN_13
                ];
                
                // Start scanning
                html5QrcodeScanner.start(
                    { facingMode: "environment" },
                    {
                        fps: 10,
                        qrbox: { width: 250, height: 250 },
                        formatsToSupport: formatsToSupport
                    },
                    (decodedText, decodedResult) => {
                        // On successful scan
                        document.getElementById('scanResult').innerHTML = 
                            `<strong>Code:</strong> ${decodedText}<br>
                             <strong>Format:</strong> ${decodedResult.result.format.formatName}`;
                        
                        logMessage(`SUCCESS: Scanned ${decodedResult.result.format.formatName} code: ${decodedText}`);
                        
                        // Optional: Stop after successful scan
                        // html5QrcodeScanner.stop();
                        // startButton.style.display = 'inline-block';
                        // stopButton.style.display = 'none';
                    },
                    (errorMessage) => {
                        // This callback is executed for each non-successful scan
                        // Don't log these as they happen continuously during scanning
                        // logMessage(`Scanner status: ${errorMessage}`);
                    }
                ).catch(error => {
                    scannerContainer.innerHTML = '<div style="text-align: center; padding: 20px; color: red;">Scanner initialization failed</div>';
                    logMessage(`ERROR: Failed to start scanner - ${error}`);
                    startButton.style.display = 'inline-block';
                    stopButton.style.display = 'none';
                });
            } catch (error) {
                scannerContainer.innerHTML = '<div style="text-align: center; padding: 20px; color: red;">Scanner error</div>';
                logMessage(`ERROR: Scanner exception - ${error.message}`);
                startButton.style.display = 'inline-block';
                stopButton.style.display = 'none';
            }
        });
        
        document.getElementById('stopScanner').addEventListener('click', function() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.stop().then(() => {
                    logMessage('Scanner stopped');
                    document.getElementById('startScanner').style.display = 'inline-block';
                    document.getElementById('stopScanner').style.display = 'none';
                    document.getElementById('qr-reader').innerHTML = '';
                }).catch(err => {
                    logMessage(`ERROR: Failed to stop scanner - ${err}`);
                });
            }
        });
        
        // Initial log message
        logMessage('Page loaded. Browser: ' + navigator.userAgent);
    </script>
</body>
</html> 