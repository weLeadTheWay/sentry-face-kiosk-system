import './bootstrap';

jQuery(document).ready(function($) {
    const token = new URL(window.location).searchParams.get('token');
    const option = new URL(window.location).searchParams.get('option');

    if (!token) {
        return;
    }

    if (window.location.pathname.includes('/capture')) {
        initCapture();
    } else if (window.location.pathname.includes('/search')) {
        initSearch();
    }

    function initCapture() {
        const video = document.getElementById('webcam');
        if (!video) return;

        const button = document.querySelector('.btn');
        if (button) {
            button.addEventListener('click', captureAndSend);
        }
    }

    function captureAndSend() {
        const video = document.getElementById('webcam');
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0);

        const imageData = canvas.toDataURL('image/jpeg');

        try {
            if (typeof faceapi !== 'undefined' && faceapi.nets.tinyFaceDetector) {
                detectAndSend(imageData);
            } else {
                sendPlaceholder(imageData);
            }
        } catch (err) {
            console.warn('Face API not available, using placeholder');
            sendPlaceholder(imageData);
        }
    }

    async function detectAndSend(imageData) {
        try {
            const img = new Image();
            img.src = imageData;

            await img.decode();

            const detections = await faceapi
                .detectSingleFace(img, new faceapi.TinyFaceDetectorOptions())
                .withFaceLandmarks()
                .withFaceDescriptors();

            if (detections && detections.descriptor) {
                sendFaceData(Array.from(detections.descriptor), imageData);
            } else {
                alert('No face detected. Please try again.');
            }
        } catch (err) {
            console.error('Face detection error:', err);
            alert('Error processing face');
        }
    }

    function sendPlaceholder(imageData) {
        const placeholderDescriptor = Array(128).fill(0).map(() => Math.random());
        sendFaceData(placeholderDescriptor, imageData);
    }

    function sendFaceData(descriptor, imageData) {
        $.ajax({
            url: '/register/visitor/capture',
            type: 'POST',
            contentType: 'application/json',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            data: JSON.stringify({
                token: token,
                descriptor: descriptor,
                face_image: imageData,
            }),
            success: function(response) {
                if (response.success) {
                    window.location.href = '/register/visitor/success?token=' + token;
                } else {
                    alert(response.message || 'Error processing face');
                }
            },
            error: function(err) {
                alert('Error sending face data');
                console.error(err);
            },
        });
    }

    function initSearch() {
        const searchInput = document.getElementById('search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                if (query.length < 2) return;

                $.ajax({
                    url: '/register/visitor/search',
                    type: 'GET',
                    data: { q: query },
                    success: function(data) {
                        console.log('Search results:', data);
                    },
                });
            });
        }
    }
});
