/**
 * Shared guided FRONT/LEFT/RIGHT face enrollment capture, used by both the
 * public Visitor Registration page (capture.blade.php) and the Kiosk
 * Gatesale/Truck self-service registration screen (kiosk/show.blade.php).
 *
 * This module owns face-api.js detection for the guided-capture UI only -
 * it never calls getUserMedia() itself. Camera acquisition/ownership always
 * stays with the host page (e.g. the kiosk's video stream is shared with
 * its own continuous recognition loop and must never be re-acquired here).
 *
 * It also never performs the actual enrollment submission/dedup request -
 * onComplete(poses) simply hands the captured poses back to the host, which
 * keeps its own existing fetch/response handling (success, already
 * registered, "Is this you?", gatesale identity confirmation, etc)
 * completely untouched.
 */
(function (global) {
    'use strict';

    var MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';

    var POSE_SEQUENCE = ['FRONT', 'LEFT', 'RIGHT'];

    var STATES = {
        IDLE: 'IDLE',
        INITIALIZING_CAMERA: 'INITIALIZING_CAMERA',
        DETECTING_FACE: 'DETECTING_FACE',
        POSITIONING: 'POSITIONING',
        FRONT_READY: 'FRONT_READY',
        FRONT_CAPTURED: 'FRONT_CAPTURED',
        LEFT_READY: 'LEFT_READY',
        LEFT_CAPTURED: 'LEFT_CAPTURED',
        RIGHT_READY: 'RIGHT_READY',
        RIGHT_CAPTURED: 'RIGHT_CAPTURED',
        PROCESSING: 'PROCESSING',
        DUPLICATE_CHECK: 'DUPLICATE_CHECK',
        COMPLETE: 'COMPLETE',
        PROCESSING_ERROR: 'PROCESSING_ERROR',
    };

    /**
     * Tunable starting-point thresholds - deliberately isolated here (not
     * buried inline) and exposed as a plain mutable object so they can be
     * recalibrated later against real enrollment footage without touching
     * the detection/state-machine logic below. None of these affect the
     * server-side match threshold (config('sentry.face.match_threshold'),
     * still 0.6, untouched) - these only gate what gets auto-captured
     * client-side during enrollment.
     */
    var CONFIG = {
        // face-api.js detector options - identical to what both the
        // existing kiosk recognition loop and the old single-shot capture
        // page already use. Must be a real TinyFaceDetectorOptions instance
        // (not a plain object) - face-api.js does an instanceof check
        // internally to pick the detector implementation, and silently
        // throws synchronously on the first tick if given a plain object.
        DETECTOR_OPTIONS: new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 }),

        // Minimum face-api.js detection confidence to consider a frame at all.
        MIN_FACE_CONFIDENCE: 0.7,

        // Face bounding-box center must be within this fraction of the
        // frame's width/height from the true center.
        CENTER_TOLERANCE_RATIO: 0.18,

        // Face bounding-box width, as a fraction of frame width, must fall
        // within this range - guards against "too far" and "too close".
        MIN_FACE_WIDTH_RATIO: 0.20,
        MAX_FACE_WIDTH_RATIO: 0.75,

        // Yaw proxy thresholds (see computeYawOffset/yawMatchesTarget below).
        // FRONT requires |offset| <= YAW_CENTER_TOLERANCE; physical LEFT
        // requires offset >= +YAW_TURN_THRESHOLD, physical RIGHT requires
        // offset <= -YAW_TURN_THRESHOLD (face-api.js reads the raw,
        // unmirrored camera frame - landmark index 0 sits at that frame's
        // left edge, which is the subject's own RIGHT side, same as facing
        // another person - see yawMatchesTarget's comment; verified against
        // real Phase C testing, not just derived). Threshold magnitudes are
        // reasoned starting defaults, not measured against real footage yet
        // - tune these first if pose detection feels too strict/loose.
        YAW_CENTER_TOLERANCE: 0.08,
        YAW_TURN_THRESHOLD: 0.18,

        // 68-point landmark indices used for the yaw proxy (standard
        // face-api.js/ibug ordering): nose tip, and the two outermost jaw
        // points bounding the face width.
        NOSE_TIP_INDEX: 30,
        JAW_LEFT_INDEX: 0,
        JAW_RIGHT_INDEX: 16,

        // Temporal stability: require this many consecutive valid ticks,
        // polled at this interval, before auto-capturing a pose.
        // 6 * 100ms = ~600ms, per the planned starting point.
        STABILITY_FRAMES: 6,
        STABILITY_INTERVAL_MS: 100,

        // Brief pause after a pose is captured so the "captured!" moment is
        // visible before the guidance advances to the next pose.
        CAPTURE_CONFIRM_DELAY_MS: 500,
    };

    function ensureModelsLoaded() {
        var loaders = [];
        if (!faceapi.nets.tinyFaceDetector.isLoaded) {
            loaders.push(faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL));
        }
        if (!faceapi.nets.faceLandmark68Net.isLoaded) {
            loaders.push(faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL));
        }
        if (!faceapi.nets.faceRecognitionNet.isLoaded) {
            loaders.push(faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL));
        }
        return loaders.length ? Promise.all(loaders) : Promise.resolve();
    }

    /**
     * face-api.js gives 2D landmarks only, no direct head-pose/yaw angle.
     * This is the standard "nose tip position relative to jaw width" proxy:
     * ~0 when facing the camera straight on, negative/positive as the head
     * rotates. Formula is unchanged from the original implementation -
     * only yawMatchesTarget()'s sign mapping below was corrected (Phase C
     * testing found LEFT/RIGHT were swapped from the user's physical
     * perspective).
     */
    function computeYawOffset(landmarks) {
        var points = landmarks.positions;
        var nose = points[CONFIG.NOSE_TIP_INDEX];
        var jawLeft = points[CONFIG.JAW_LEFT_INDEX];
        var jawRight = points[CONFIG.JAW_RIGHT_INDEX];
        var faceWidth = jawRight.x - jawLeft.x;
        if (!faceWidth) {
            return 0;
        }
        return ((nose.x - jawLeft.x) / faceWidth) - 0.5;
    }

    /**
     * Maps computeYawOffset()'s signed value to a physical direction.
     *
     * face-api.js detects against the RAW camera frame, not the mirrored
     * on-screen preview (the video element's CSS `transform: scaleX(-1)`
     * only changes what's rendered, not the pixel data face-api.js reads).
     * In the raw frame the camera is effectively facing the user, the same
     * as two people facing each other - so the user's own RIGHT side falls
     * on the LEFT side of the raw frame (smaller x), same convention as the
     * standard 68-point jaw ordering (landmark index 0 = frame-left = the
     * subject's own right jaw; index 16 = frame-right = the subject's own
     * left jaw). That means nose.x shifts toward jawLeft (index 0, smaller
     * x) - a NEGATIVE offset - when the user turns their physical RIGHT,
     * and toward jawRight (index 16, larger x) - a POSITIVE offset - when
     * they turn their physical LEFT. Confirmed against real Phase C manual
     * testing (the previous version had this exact mapping inverted).
     */
    function yawMatchesTarget(offset, targetPose) {
        if (targetPose === 'FRONT') {
            return Math.abs(offset) <= CONFIG.YAW_CENTER_TOLERANCE;
        }
        if (targetPose === 'LEFT') {
            return offset >= CONFIG.YAW_TURN_THRESHOLD;
        }
        if (targetPose === 'RIGHT') {
            return offset <= -CONFIG.YAW_TURN_THRESHOLD;
        }
        return false;
    }

    function isCentered(box, videoEl) {
        var cx = box.x + box.width / 2;
        var cy = box.y + box.height / 2;
        var dx = Math.abs(cx - videoEl.videoWidth / 2) / videoEl.videoWidth;
        var dy = Math.abs(cy - videoEl.videoHeight / 2) / videoEl.videoHeight;
        return dx <= CONFIG.CENTER_TOLERANCE_RATIO && dy <= CONFIG.CENTER_TOLERANCE_RATIO;
    }

    function isSizeOk(box, videoEl) {
        var widthRatio = box.width / videoEl.videoWidth;
        return widthRatio >= CONFIG.MIN_FACE_WIDTH_RATIO && widthRatio <= CONFIG.MAX_FACE_WIDTH_RATIO;
    }

    /**
     * Pure classification of one detection tick against the current target
     * pose - no side effects, easy to reason about/exercise independently
     * of the timer-driven loop in start() below.
     *
     * Returns { state, valid, reason?, yawOffset? }. `state` always follows
     * the required IDLE->...->COMPLETE sequence (DETECTING_FACE/POSITIONING/
     * <POSE>_READY are the only "waiting" states a tick can land on); the
     * finer-grained reason (NO_FACE, MULTIPLE_FACES, TOO_FAR, TOO_CLOSE,
     * OFF_CENTER, WRONG_POSE) rides along in `reason` for host UI messaging
     * without inventing extra top-level states.
     */
    function classifyTick(detections, videoEl, targetPose) {
        if (detections.length === 0) {
            return { state: STATES.DETECTING_FACE, valid: false, reason: 'NO_FACE' };
        }
        if (detections.length > 1) {
            return { state: STATES.DETECTING_FACE, valid: false, reason: 'MULTIPLE_FACES' };
        }

        var detection = detections[0].detection;
        var landmarks = detections[0].landmarks;

        if (detection.score < CONFIG.MIN_FACE_CONFIDENCE) {
            return { state: STATES.DETECTING_FACE, valid: false, reason: 'LOW_CONFIDENCE' };
        }

        if (!isSizeOk(detection.box, videoEl)) {
            var widthRatio = detection.box.width / videoEl.videoWidth;
            return {
                state: STATES.POSITIONING,
                valid: false,
                reason: widthRatio < CONFIG.MIN_FACE_WIDTH_RATIO ? 'TOO_FAR' : 'TOO_CLOSE',
            };
        }

        if (!isCentered(detection.box, videoEl)) {
            return { state: STATES.POSITIONING, valid: false, reason: 'OFF_CENTER' };
        }

        var yawOffset = computeYawOffset(landmarks);
        var readyState = STATES[targetPose + '_READY'];

        if (!yawMatchesTarget(yawOffset, targetPose)) {
            return { state: readyState, valid: false, reason: 'WRONG_POSE', yawOffset: yawOffset };
        }

        return { state: readyState, valid: true, yawOffset: yawOffset };
    }

    function sleep(ms) {
        return new Promise(function (resolve) {
            setTimeout(resolve, ms);
        });
    }

    /**
     * Starts one guided enrollment capture.
     *
     * options:
     *   videoEl          HTMLVideoElement, already playing a live stream
     *                     (this module never touches getUserMedia)
     *   onStateChange(state, meta)  called on every state transition/tick;
     *                     `meta` carries { pose, reason, streak, target,
     *                     yawOffset } as applicable - see classifyTick()
     *   onComplete(poses) called once, with { FRONT: {descriptor,
     *                     face_image}, LEFT: {...}, RIGHT: {...} }, once
     *                     all three poses are captured. Never called with a
     *                     partial/fabricated set - if the flow is stopped
     *                     early it is simply never called at all.
     *   onError(err)      called on an unexpected/fatal error (model load
     *                     failure, etc)
     *
     * Returns { stop() } - aborts the flow (e.g. a Cancel button). Safe to
     * call at any point, including after completion (no-op).
     */
    function start(options) {
        var videoEl = options.videoEl;
        var onStateChange = options.onStateChange || function () {};
        var onComplete = options.onComplete || function () {};
        var onError = options.onError || function () {};

        var stopped = false;
        var timerId = null;
        var poseIndex = 0;
        var streak = 0;
        var capturedPoses = {};

        function emit(state, meta) {
            if (stopped) {
                return;
            }
            onStateChange(state, meta || {});
        }

        function stop() {
            stopped = true;
            if (timerId) {
                clearTimeout(timerId);
                timerId = null;
            }
        }

        function scheduleNextTick() {
            if (stopped) {
                return;
            }
            timerId = setTimeout(tick, CONFIG.STABILITY_INTERVAL_MS);
        }

        function tick() {
            if (stopped) {
                return;
            }

            if (videoEl.readyState < 2) {
                streak = 0;
                emit(STATES.DETECTING_FACE, { pose: POSE_SEQUENCE[poseIndex], reason: 'NO_FACE' });
                scheduleNextTick();
                return;
            }

            var targetPose = POSE_SEQUENCE[poseIndex];

            faceapi.detectAllFaces(videoEl, CONFIG.DETECTOR_OPTIONS).withFaceLandmarks()
                .then(function (detections) {
                    if (stopped) {
                        return;
                    }

                    var result = classifyTick(detections, videoEl, targetPose);

                    if (result.valid) {
                        streak++;
                        emit(result.state, { pose: targetPose, streak: streak, target: CONFIG.STABILITY_FRAMES, yawOffset: result.yawOffset });

                        if (streak >= CONFIG.STABILITY_FRAMES) {
                            return captureCurrentPose(targetPose);
                        }
                    } else {
                        streak = 0;
                        emit(result.state, { pose: targetPose, reason: result.reason });
                    }
                })
                .catch(function (err) {
                    emit(STATES.PROCESSING_ERROR, { message: err && err.message });
                    onError(err);
                })
                .then(function () {
                    scheduleNextTick();
                });
        }

        function captureCurrentPose(targetPose) {
            return faceapi
                .detectSingleFace(videoEl, CONFIG.DETECTOR_OPTIONS)
                .withFaceLandmarks()
                .withFaceDescriptor()
                .then(function (detection) {
                    if (stopped) {
                        return;
                    }

                    if (!detection) {
                        // Lost the face at the very last instant - stay on
                        // this pose, next tick picks the streak back up.
                        streak = 0;
                        return;
                    }

                    var canvas = document.createElement('canvas');
                    canvas.width = videoEl.videoWidth;
                    canvas.height = videoEl.videoHeight;
                    canvas.getContext('2d').drawImage(videoEl, 0, 0);

                    capturedPoses[targetPose] = {
                        descriptor: Array.from(detection.descriptor),
                        face_image: canvas.toDataURL('image/jpeg'),
                    };

                    streak = 0;
                    emit(STATES[targetPose + '_CAPTURED'], { pose: targetPose });

                    return sleep(CONFIG.CAPTURE_CONFIRM_DELAY_MS).then(function () {
                        if (stopped) {
                            return;
                        }

                        poseIndex++;
                        if (poseIndex < POSE_SEQUENCE.length) {
                            emit(STATES[POSE_SEQUENCE[poseIndex] + '_READY'], { pose: POSE_SEQUENCE[poseIndex] });
                        } else {
                            finish();
                        }
                    });
                });
        }

        function finish() {
            stop();
            emit(STATES.PROCESSING, {});
            emit(STATES.DUPLICATE_CHECK, {});
            emit(STATES.COMPLETE, { poses: capturedPoses });
            try {
                onComplete(capturedPoses);
            } catch (err) {
                onError(err);
            }
        }

        function waitForVideoReady() {
            return new Promise(function (resolve) {
                (function poll() {
                    if (stopped || videoEl.readyState >= 2) {
                        resolve();
                        return;
                    }
                    setTimeout(poll, 100);
                })();
            });
        }

        emit(STATES.IDLE, {});
        emit(STATES.INITIALIZING_CAMERA, {});

        ensureModelsLoaded()
            .then(waitForVideoReady)
            .then(function () {
                if (stopped) {
                    return;
                }
                emit(STATES.DETECTING_FACE, { pose: POSE_SEQUENCE[poseIndex] });
                scheduleNextTick();
            })
            .catch(function (err) {
                emit(STATES.PROCESSING_ERROR, { message: 'Failed to initialize camera or face recognition models.' });
                onError(err);
            });

        return { stop: stop };
    }

    global.FaceEnrollment = {
        STATES: STATES,
        POSE_SEQUENCE: POSE_SEQUENCE,
        config: CONFIG,
        start: start,
        // Exposed for potential future testing/tuning - not used by the
        // start() flow directly outside of classifyTick().
        _internal: {
            computeYawOffset: computeYawOffset,
            yawMatchesTarget: yawMatchesTarget,
            isCentered: isCentered,
            isSizeOk: isSizeOk,
            classifyTick: classifyTick,
        },
    };
})(window);
