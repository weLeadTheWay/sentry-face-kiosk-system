<div class="content-header">
    <h2 class="content-title">Edit Kiosk</h2>
</div>

<div class="table-wrapper" style="max-width: 600px;">
    <form method="POST" action="{{ route('kiosks.update', $kiosk_device) }}" class="ajax-form" style="padding: 1.5rem;">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label for="farm_id">Farm *</label>
            <select id="farm_id" name="farm_id" required>
                @foreach($farms as $f)
                    <option value="{{ $f->farm_id }}" {{ $f->farm_id == $kiosk_device->farm_id ? 'selected' : '' }}>{{ $f->farm_name }}</option>
                @endforeach
            </select>
        </div>

        <div class="form-group">
            <label for="device_name">Device Name *</label>
            <input type="text" id="device_name" name="device_name" value="{{ $kiosk_device->device_name }}" required>
        </div>

        <div class="form-group">
            <label for="serial_number">Serial Number *</label>
            <input type="text" id="serial_number" name="serial_number" value="{{ $kiosk_device->serial_number }}" required>
        </div>

        <div class="form-group">
            <label for="device_type">Device Type</label>
            <input type="text" id="device_type" name="device_type" value="{{ $kiosk_device->device_type }}">
        </div>

        <div class="form-group">
            <label for="public_ip">IP Address</label>
            <input type="text" id="public_ip" name="public_ip" value="{{ $kiosk_device->public_ip }}">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Update</button>
            <a href="{{ route('kiosks.index') }}" class="btn btn-secondary ajax-link">Cancel</a>
        </div>
    </form>

    <div style="padding: 1.5rem; border-top: 1px solid #eee;">
        <div class="form-group">
            <label for="kiosk_token">Kiosk Token</label>
            <div style="display: flex; gap: 0.5rem;">
                <input type="text" id="kiosk_token" value="{{ $kiosk_device->kiosk_token }}" readonly style="flex: 1; background: #f8f9fa;">
                <button type="button" class="btn btn-secondary" onclick="copyKioskToken()">Copy</button>
            </div>
            <p style="font-size: 12px; color: #666; margin-top: 6px;">Enter this token on the physical kiosk's setup screen to pair it with this device.</p>
        </div>

        <form method="POST" action="{{ route('kiosks.regenerate-token', $kiosk_device) }}" class="ajax-form" onsubmit="return confirm('Regenerating will invalidate the token currently stored on the physical kiosk. Continue?');">
            @csrf
            <button type="submit" class="btn btn-danger">Regenerate Token</button>
        </form>
    </div>
</div>

<script>
    function copyKioskToken() {
        const input = document.getElementById('kiosk_token');
        input.select();
        navigator.clipboard.writeText(input.value);
    }
</script>
