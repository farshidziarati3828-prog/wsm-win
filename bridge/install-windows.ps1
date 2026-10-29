#Requires -Version 5.1
[CmdletBinding()]
param([switch]$Configure)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
try {
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
} catch {
    Write-Error 'اجزای رابط Windows در دسترس نیستند. Windows PowerShell 5.1 را اجرا کنید.'
    exit 1
}
[System.Windows.Forms.Application]::EnableVisualStyles()

$script:SourceRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$localAppData = if ($env:LOCALAPPDATA) { $env:LOCALAPPDATA } else { [Environment]::GetFolderPath([Environment+SpecialFolder]::LocalApplicationData) }
$script:InstallRoot = if ($Configure) { $script:SourceRoot } else { Join-Path $localAppData 'Programs\WP Store Manager Bridge' }
$script:InstallLog = Join-Path $env:TEMP 'wsm-bridge-install.log'
$script:PythonExe = $null
$script:PythonPrefix = @()
$script:Step = 0
$script:OptionalHardwareInstallError = $null
$script:OptionalHardwareInstallAdvice = $null
$script:VenvPython = Join-Path $script:InstallRoot '.venv\Scripts\python.exe'
$script:BridgeScript = Join-Path $script:InstallRoot 'wsm_local_bridge.py'

function New-UiLabel([string]$Text,[int]$X,[int]$Y,[int]$W,[int]$H = 28,[int]$FontSize = 10) {
    $l = New-Object System.Windows.Forms.Label
    $l.Text = $Text
    $l.Location = New-Object System.Drawing.Point($X,$Y)
    $l.Size = New-Object System.Drawing.Size($W,$H)
    $l.TextAlign = [System.Drawing.ContentAlignment]::MiddleRight
    $l.Font = New-Object System.Drawing.Font('Segoe UI',$FontSize)
    $l.RightToLeft = [System.Windows.Forms.RightToLeft]::Yes
    return $l
}
function New-UiTextBox([int]$X,[int]$Y,[int]$W,[int]$H = 28,[string]$Text = '') {
    $t = New-Object System.Windows.Forms.TextBox
    $t.Location = New-Object System.Drawing.Point($X,$Y)
    $t.Size = New-Object System.Drawing.Size($W,$H)
    $t.Text = $Text
    $t.Font = New-Object System.Drawing.Font('Segoe UI',10)
    $t.RightToLeft = [System.Windows.Forms.RightToLeft]::No
    return $t
}
function New-UiButton([string]$Text,[int]$X,[int]$Y,[int]$W = 142,[int]$H = 34) {
    $b = New-Object System.Windows.Forms.Button
    $b.Text = $Text
    $b.Location = New-Object System.Drawing.Point($X,$Y)
    $b.Size = New-Object System.Drawing.Size($W,$H)
    $b.Font = New-Object System.Drawing.Font('Segoe UI',9)
    return $b
}
function New-UiStatusBox([int]$X,[int]$Y,[int]$W,[int]$H) {
    $t = New-UiTextBox $X $Y $W $H ''
    $t.Multiline = $true
    $t.ReadOnly = $true
    $t.ScrollBars = [System.Windows.Forms.ScrollBars]::Vertical
    $t.RightToLeft = [System.Windows.Forms.RightToLeft]::Yes
    $t.BackColor = [System.Drawing.Color]::FromArgb(248,250,252)
    return $t
}
function Get-DefaultText([string]$Value,[string]$Fallback) {
    if ([string]::IsNullOrWhiteSpace($Value)) { return $Fallback }
    return $Value.Trim()
}
function Test-PrivateDeviceIP([string]$Value) {
    $address = $null
    if (-not [System.Net.IPAddress]::TryParse($Value.Trim(),[ref]$address)) { return $false }
    if ($address.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork) { return $false }
    if ([System.Net.IPAddress]::IsLoopback($address)) { return $true }
    $bytes = $address.GetAddressBytes()
    return (($bytes[0] -eq 10) -or ($bytes[0] -eq 172 -and $bytes[1] -ge 16 -and $bytes[1] -le 31) -or ($bytes[0] -eq 192 -and $bytes[1] -eq 168) -or ($bytes[0] -eq 169 -and $bytes[1] -eq 254))
}
function Set-Status([string]$Text) {
    if ($script:statusLabel) {
        $script:statusLabel.Text = $Text
        $script:statusLabel.Refresh()
        [System.Windows.Forms.Application]::DoEvents()
    }
}
function Set-SettingsStatus([string]$Text) {
    if ($script:settingsStatus) {
        $script:settingsStatus.Text = $Text
        $script:settingsStatus.Refresh()
        [System.Windows.Forms.Application]::DoEvents()
    }
}
function Quote-ProcessArgument([string]$Value) {
    # Windows command-line quoting (including trailing backslashes) for ProcessStartInfo.Arguments.
    $quote = [char]34
    $backslash = [char]92
    $builder = New-Object System.Text.StringBuilder
    [void]$builder.Append($quote)
    $slashes = 0
    foreach ($character in $Value.ToCharArray()) {
        if ($character -eq $backslash) {
            $slashes++
        } elseif ($character -eq $quote) {
            for ($n=0; $n -lt ($slashes * 2); $n++) { [void]$builder.Append($backslash) }
            [void]$builder.Append($backslash)
            [void]$builder.Append($quote)
            $slashes = 0
        } else {
            for ($n=0; $n -lt $slashes; $n++) { [void]$builder.Append($backslash) }
            [void]$builder.Append($character)
            $slashes = 0
        }
    }
    if ($slashes -gt 0) { for ($n=0; $n -lt ($slashes * 2); $n++) { [void]$builder.Append($backslash) } }
    [void]$builder.Append($quote)
    return $builder.ToString()
}
function Invoke-Native([string]$FilePath,[string[]]$ArgumentList,[string]$Description) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $FilePath
    $psi.WorkingDirectory = $script:InstallRoot
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.StandardOutputEncoding = [System.Text.Encoding]::UTF8
    $psi.StandardErrorEncoding = [System.Text.Encoding]::UTF8
    $psi.Arguments = (($ArgumentList | ForEach-Object { Quote-ProcessArgument ([string]$_) }) -join ' ')
    $psi.EnvironmentVariables['PYTHONUTF8'] = '1'
    $psi.EnvironmentVariables['PYTHONIOENCODING'] = 'utf-8'
    $proc = New-Object System.Diagnostics.Process
    $proc.StartInfo = $psi
    if (-not $proc.Start()) { throw "اجرای $Description آغاز نشد." }
    $outTask = $proc.StandardOutput.ReadToEndAsync()
    $errTask = $proc.StandardError.ReadToEndAsync()
    while ($true) {
        $finished = $proc.WaitForExit(180)
        if ($finished) { break }
        [System.Windows.Forms.Application]::DoEvents()
        Start-Sleep -Milliseconds 120
    }
    $proc.WaitForExit()
    $outText = $outTask.GetAwaiter().GetResult()
    $errText = $errTask.GetAwaiter().GetResult()
    if ($outText) { Add-Content -LiteralPath $script:InstallLog -Value $outText -Encoding UTF8 }
    if ($errText) { Add-Content -LiteralPath $script:InstallLog -Value $errText -Encoding UTF8 }
    if ($proc.ExitCode -ne 0) {
        $detail = ($errText + "`n" + $outText).Trim()
        if (-not $detail) { $detail = "کد خطا: $($proc.ExitCode)" }
        throw "$Description ناموفق بود.`n$detail"
    }
    return $outText.Trim()
}
function Invoke-NativeJson([string]$FilePath,[string[]]$ArgumentList,[hashtable]$Payload,[string]$Description) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $FilePath
    $psi.WorkingDirectory = $script:InstallRoot
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardInput = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.StandardOutputEncoding = [System.Text.Encoding]::UTF8
    $psi.StandardErrorEncoding = [System.Text.Encoding]::UTF8
    $psi.Arguments = (($ArgumentList | ForEach-Object { Quote-ProcessArgument ([string]$_) }) -join ' ')
    $psi.EnvironmentVariables['PYTHONUTF8'] = '1'
    $psi.EnvironmentVariables['PYTHONIOENCODING'] = 'utf-8'
    $proc = New-Object System.Diagnostics.Process
    $proc.StartInfo = $psi
    if (-not $proc.Start()) { throw "اجرای $Description آغاز نشد." }
    $outTask = $proc.StandardOutput.ReadToEndAsync()
    $errTask = $proc.StandardError.ReadToEndAsync()
    $json = ConvertTo-Json -InputObject $Payload -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json + "`n")
    $proc.StandardInput.BaseStream.Write($bytes,0,$bytes.Length)
    $proc.StandardInput.BaseStream.Flush()
    $proc.StandardInput.Close()
    while ($true) {
        $finished = $proc.WaitForExit(180)
        if ($finished) { break }
        [System.Windows.Forms.Application]::DoEvents()
        Start-Sleep -Milliseconds 120
    }
    $proc.WaitForExit()
    $outText = $outTask.GetAwaiter().GetResult()
    $errText = $errTask.GetAwaiter().GetResult()
    if ($outText) { Add-Content -LiteralPath $script:InstallLog -Value $outText -Encoding UTF8 }
    if ($errText) { Add-Content -LiteralPath $script:InstallLog -Value $errText -Encoding UTF8 }
    if ($proc.ExitCode -ne 0) {
        $detail = ($errText + "`n" + $outText).Trim()
        if (-not $detail) { $detail = "کد خطا: $($proc.ExitCode)" }
        throw "$Description ناموفق بود.`n$detail"
    }
    return $outText.Trim()
}

function Invoke-InterpreterCapture([string]$Executable,[string[]]$Arguments) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $Executable
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    try {
        $psi.StandardOutputEncoding = [System.Text.Encoding]::UTF8
        $psi.StandardErrorEncoding = [System.Text.Encoding]::UTF8
    } catch { }
    $psi.Arguments = (($Arguments | ForEach-Object { Quote-ProcessArgument ([string]$_) }) -join ' ')
    $proc = New-Object System.Diagnostics.Process
    try {
        $proc.StartInfo = $psi
        if (-not $proc.Start()) { throw 'اجرای Python آغاز نشد.' }
        $outTask = $proc.StandardOutput.ReadToEndAsync()
        $errTask = $proc.StandardError.ReadToEndAsync()
        if (-not $proc.WaitForExit(15000)) {
            try { $proc.Kill(); $proc.WaitForExit() } catch { }
            throw 'Python در مهلت بررسی نسخه پاسخ نداد.'
        }
        $proc.WaitForExit()
        $outText = $outTask.GetAwaiter().GetResult()
        $errText = $errTask.GetAwaiter().GetResult()
        return [pscustomobject]@{ ExitCode=$proc.ExitCode; Stdout=$outText; Stderr=$errText }
    } finally {
        if ($proc) { $proc.Dispose() }
    }
}
function Test-PythonExecutable([string]$Executable,[string[]]$Prefix = @()) {
    if ([string]::IsNullOrWhiteSpace($Executable)) { return $null }
    if (-not (Test-Path -LiteralPath $Executable -PathType Leaf)) { return $null }
    try {
        # Ask the selected executable/launcher for its normal version banner; capture both streams
        # because Python builds can write --version to stdout or stderr.
        $pythonArgs = @()
        if ($Prefix) { $pythonArgs += $Prefix }
        $pythonArgs += '--version'
        $probe = Invoke-InterpreterCapture $Executable $pythonArgs
        if (-not $probe -or $probe.ExitCode -ne 0) { return $null }
        $versionText = ($probe.Stdout + "`n" + $probe.Stderr).Trim()
        $versionMatch = [regex]::Match($versionText,'(?im)^\s*Python\s+(\d+)\.(\d+)(?:\.(\d+))?(?:[^\r\n]*)?$')
        if (-not $versionMatch.Success) { return $null }
        $major = [int]$versionMatch.Groups[1].Value
        $minor = [int]$versionMatch.Groups[2].Value
        if ($major -lt 3 -or ($major -eq 3 -and $minor -lt 10)) { return $null }
        $version = "$major.$minor"
        if ($versionMatch.Groups[3].Success) { $version += '.' + $versionMatch.Groups[3].Value }
        $resolved = $Executable
        try { $resolved = [IO.Path]::GetFullPath($Executable) } catch { }

        # Keep py.exe support for auto-detection, but display the interpreter selected by that launcher.
        if ([IO.Path]::GetFileName($Executable) -ieq 'py.exe') {
            try {
                $resolveArgs = @()
                if ($Prefix) { $resolveArgs += $Prefix }
                $resolveArgs += @('-c','import sys;print(sys.executable)')
                $resolveProbe = Invoke-InterpreterCapture $Executable $resolveArgs
                if ($resolveProbe -and $resolveProbe.ExitCode -eq 0) {
                    foreach ($line in ($resolveProbe.Stdout -split '\r?\n')) {
                        $resolvedPath = $line.Trim().Trim([char]34)
                        if ($resolvedPath -and (Test-Path -LiteralPath $resolvedPath -PathType Leaf)) {
                            $resolved = [IO.Path]::GetFullPath($resolvedPath)
                            break
                        }
                    }
                }
            } catch { }
        }
        return [pscustomobject]@{ Exe=$Executable; Prefix=@($Prefix); Version=$version; Resolved=$resolved }
    } catch {
        return $null
    }
}
function Add-PythonCandidate([System.Collections.ArrayList]$List,[string]$Executable,[string[]]$Prefix = @()) {
    if ([string]::IsNullOrWhiteSpace($Executable)) { return }
    $key = $Executable.Trim().ToLowerInvariant() + '|' + ($Prefix -join ' ')
    foreach ($existing in $List) {
        if ($existing.Key -eq $key) { return }
    }
    [void]$List.Add([pscustomobject]@{Key=$key;Exe=$Executable.Trim();Prefix=@($Prefix)})
}
function Get-PythonInfo {
    # A PowerShell window started from Explorer/elevated credentials may inherit a different PATH than CMD.
    # Merge the current process with the persisted user and machine PATH values before looking up executables.
    $pathParts = @()
    foreach ($pathValue in @($env:Path,[Environment]::GetEnvironmentVariable('Path','User'),[Environment]::GetEnvironmentVariable('Path','Machine'))) {
        if ($pathValue) { $pathParts += @($pathValue -split ';' | Where-Object { $_ -and $_.Trim() }) }
    }
    if ($pathParts.Count -gt 0) { $env:Path = ($pathParts | Select-Object -Unique) -join ';' }

    $candidates = New-Object System.Collections.ArrayList
    foreach ($commandName in @('py.exe','python.exe','python3.exe')) {
        $commands = @(Get-Command $commandName -CommandType Application -All -ErrorAction SilentlyContinue)
        foreach ($command in $commands) {
            $commandPath = if ($command.Path) { $command.Path } else { $command.Source }
            if (-not $commandPath) { $commandPath = $command.Definition }
            if ($commandName -eq 'py.exe') { Add-PythonCandidate $candidates $commandPath @('-3') }
            else { Add-PythonCandidate $candidates $commandPath @() }
        }
    }

    # Use the Python launcher inventory when available; it finds per-user installs even when PATH is incomplete.
    $launcher = Get-Command py.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($launcher) {
        $launcherPath = if ($launcher.Path) { $launcher.Path } else { $launcher.Source }
        if ($launcherPath) {
            try {
                $inventory = @(& $launcherPath -0p 2>$null | ForEach-Object { [string]$_ })
                foreach ($line in $inventory) {
                    if ($line -match '(?i)([a-z]:\\.*\\python(?:3)?\.exe)\s*$') { Add-PythonCandidate $candidates $Matches[1] @() }
                }
            } catch { }
        }
    }

    $roots = @()
    if ($env:LOCALAPPDATA) { $roots += (Join-Path $env:LOCALAPPDATA 'Programs\Python') }
    foreach ($rootName in @('ProgramFiles','ProgramW6432','ProgramFiles(x86)')) {
        $rootValue = [Environment]::GetEnvironmentVariable($rootName)
        if ($rootValue) { $roots += $rootValue }
    }
    foreach ($root in ($roots | Select-Object -Unique)) {
        if (Test-Path -LiteralPath $root) {
            foreach ($directory in (Get-ChildItem -LiteralPath $root -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '^Python3\d+' })) {
                Add-PythonCandidate $candidates (Join-Path $directory.FullName 'python.exe') @()
            }
        }
    }

    # Python.org/Python Install Manager may register the executable here even if the command shell was not refreshed.
    $registryPatterns = @(
        'HKCU:\Software\Python\PythonCore\*\InstallPath',
        'HKLM:\Software\Python\PythonCore\*\InstallPath',
        'HKLM:\Software\WOW6432Node\Python\PythonCore\*\InstallPath'
    )
    foreach ($pattern in $registryPatterns) {
        foreach ($entry in @(Get-ItemProperty -Path $pattern -ErrorAction SilentlyContinue)) {
            $exePath = [string]$entry.ExecutablePath
            if (-not $exePath) {
                try {
                    $key = Get-Item -LiteralPath $entry.PSPath -ErrorAction Stop
                    $installDir = [string]$key.GetValue('')
                    if ($installDir) { $exePath = Join-Path $installDir 'python.exe' }
                } catch { }
            }
            if ($exePath) { Add-PythonCandidate $candidates $exePath @() }
        }
    }

    # where.exe can return additional PATH matches that are hidden by a command alias.
    $where = Get-Command where.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($where) {
        $wherePath = if ($where.Path) { $where.Path } else { $where.Source }
        foreach ($name in @('python.exe','python3.exe')) {
            try {
                foreach ($foundPath in @(& $wherePath $name 2>$null)) { Add-PythonCandidate $candidates ([string]$foundPath) @() }
            } catch { }
        }
    }
    foreach ($candidate in $candidates) {
        $tested = Test-PythonExecutable $candidate.Exe $candidate.Prefix
        if ($tested) { return $tested }
    }
    return $null
}
function Find-PythonAfterInstall {
    for ($attempt=0; $attempt -lt 12; $attempt++) {
        $found = Get-PythonInfo
        if ($found) { return $found }
        [System.Windows.Forms.Application]::DoEvents()
        Start-Sleep -Milliseconds 900
    }
    return $null
}
function Install-OfficialPython {
    $arch = [string]$env:PROCESSOR_ARCHITEW6432
    if (-not $arch) { $arch = [string]$env:PROCESSOR_ARCHITECTURE }
    if ($arch -ieq 'ARM64') {
        $fileName = 'python-3.13.16-arm64.exe'
        $sha256 = '696e2226062c6ec3622c13f143d28336a856968518e49cb4f4c62513215a4f5d'
    } elseif ([Environment]::Is64BitOperatingSystem) {
        $fileName = 'python-3.13.16-amd64.exe'
        $sha256 = 'fb4f9f5d438b2396da0086dc70b935c530cb578e37adc6d354f7ad2037fee83b'
    } else {
        $fileName = 'python-3.13.16.exe'
        $sha256 = '394ca84150ea428db952cb617c1163c49fbc30e47ec53048c26e6ae44265df07'
    }
    $installer = Join-Path $env:TEMP $fileName
    $url = 'https://www.python.org/ftp/python/3.13.16/' + $fileName
    Set-Status 'Python پیدا نشد؛ نصب‌گر رسمی Python 3.13.16 از python.org دریافت می‌شود…'
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $installer -TimeoutSec 180
    $actualHash = (Get-FileHash -LiteralPath $installer -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualHash -ne $sha256) {
        Remove-Item -LiteralPath $installer -Force -ErrorAction SilentlyContinue
        throw 'تأیید SHA-256 نصب‌گر Python ناموفق بود؛ فایل اجرا نشد.'
    }
    $pythonTarget = Join-Path $env:LOCALAPPDATA 'Programs\Python\Python313'
    Set-Status 'در حال نصب Python برای حساب Windows فعلی…'
    $installerArgs = @('/quiet','/norestart','InstallAllUsers=0','PrependPath=0','Include_pip=1','Include_launcher=0','Include_doc=0','Include_test=0','Include_tcltk=0','Shortcuts=0',('TargetDir=' + $pythonTarget))
    try {
        Invoke-Native $installer $installerArgs 'نصب رسمی Python' | Out-Null
    } finally {
        Remove-Item -LiteralPath $installer -Force -ErrorAction SilentlyContinue
    }
}
function Ensure-Python {
    if ($script:txtPythonExe -and -not [string]::IsNullOrWhiteSpace($script:txtPythonExe.Text)) {
        $selectedPath = $script:txtPythonExe.Text.Trim()
        $selected = Test-PythonExecutable $selectedPath @()
        if ($selected) {
            $script:PythonExe = $selected.Exe
            $script:PythonPrefix = @()
            Set-Status ("Python $($selected.Version) انتخاب شد: $($selected.Resolved)")
            return
        }
        [System.Windows.Forms.MessageBox]::Show('از فایل انتخاب‌شده پاسخ معتبر «python.exe --version» خوانده نشد؛ فایل اجرایی Python 3.10 یا جدیدتر را انتخاب کنید.','Python نامعتبر','OK','Warning') | Out-Null
        $script:txtPythonExe.Clear()
    }
    $found = Get-PythonInfo
    if ($found) {
        $script:PythonExe = $found.Exe
        $script:PythonPrefix = @($found.Prefix)
        if ($script:txtPythonExe) { $script:txtPythonExe.Text = $found.Resolved }
        Set-Status ("Python $($found.Version) شناسایی شد: $($found.Resolved)")
        return
    }

    # Let the user select an already-installed interpreter instead of forcing WinGet or an Internet download.
    $choice = [System.Windows.Forms.MessageBox]::Show('Python در مسیرهای خودکار پیدا نشد. اگر Python را قبلاً نصب کرده‌اید، «بله» را بزنید و python.exe را انتخاب کنید. برای نصب خودکار آنلاین، «خیر» را بزنید.','انتخاب Python',[System.Windows.Forms.MessageBoxButtons]::YesNo,[System.Windows.Forms.MessageBoxIcon]::Question)
    if ($choice -eq [System.Windows.Forms.DialogResult]::Yes) {
        $dialog = New-Object System.Windows.Forms.OpenFileDialog
        $dialog.Title = 'فایل Python نصب‌شده را انتخاب کنید'
        $dialog.Filter = 'Python executable (python.exe)|python.exe|همهٔ فایل‌ها (*.*)|*.*'
        $dialog.CheckFileExists = $true
        if ($dialog.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) {
            $selected = Test-PythonExecutable $dialog.FileName @()
            if ($selected) {
                $script:PythonExe = $selected.Exe
                $script:PythonPrefix = @()
                $script:txtPythonExe.Text = $selected.Resolved
                Set-Status ("Python $($selected.Version) انتخاب شد: $($selected.Resolved)")
                return
            }
            [System.Windows.Forms.MessageBox]::Show('پاسخ «python.exe --version» معتبر نیست یا نسخه کمتر از 3.10 است؛ فایل اجرایی Python 3.10 یا جدیدتر را انتخاب کنید.','Python نامعتبر','OK','Warning') | Out-Null
        }
    }

    $wingetError = ''
    $winget = Get-Command winget.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($winget) {
        $wingetPath = if ($winget.Path) { $winget.Path } else { $winget.Source }
        try {
            Set-Status 'Python پیدا نشد؛ نصب خودکار با WinGet در حال تلاش است…'
            Invoke-Native $wingetPath @('install','--id','Python.Python.3.13','--exact','--scope','user','--silent','--accept-package-agreements','--accept-source-agreements') 'نصب Python با WinGet' | Out-Null
            $found = Find-PythonAfterInstall
            if ($found) {
                $script:PythonExe = $found.Exe
                $script:PythonPrefix = @($found.Prefix)
                $script:txtPythonExe.Text = $found.Resolved
                return
            }
            $wingetError = 'WinGet تمام شد اما فایل اجرایی Python پیدا نشد.'
        } catch { $wingetError = $_.Exception.Message }
    } else { $wingetError = 'WinGet در این Windows پیدا نشد.' }

    try {
        Install-OfficialPython
        $found = Find-PythonAfterInstall
        if ($found) {
            $script:PythonExe = $found.Exe
            $script:PythonPrefix = @($found.Prefix)
            $script:txtPythonExe.Text = $found.Resolved
            return
        }
        throw 'نصب تمام شد اما اجرای python.exe شناسایی نشد.'
    } catch {
        throw "Python 3.10+ پیدا نشد. مسیرهای PATH/رجیستری بررسی شدند. تلاش WinGet: $wingetError`nنصب‌گر رسمی: $($_.Exception.Message)`nاگر CMD دستور python --version را اجرا می‌کند، در پنجرهٔ wizard فایل python.exe را دستی انتخاب کنید."
    }
}

function Create-SettingsShortcut {
    $installer = Join-Path $script:InstallRoot 'install-windows.ps1'
    if (-not (Test-Path -LiteralPath $installer -PathType Leaf)) { throw 'فایل نصب‌شدهٔ تنظیمات پل پیدا نشد.' }

    # Use the Windows known-folder API; do not assume that the profile is under C:\Users.
    $programs = [Environment]::GetFolderPath([Environment+SpecialFolder]::Programs)
    if ([string]::IsNullOrWhiteSpace($programs) -or -not [IO.Path]::IsPathRooted($programs)) {
        $roaming = [Environment]::GetFolderPath([Environment+SpecialFolder]::ApplicationData)
        if ([string]::IsNullOrWhiteSpace($roaming)) { throw 'مسیر پوشهٔ Start Menu کاربر پیدا نشد.' }
        $programs = Join-Path $roaming 'Microsoft\Windows\Start Menu\Programs'
    }

    $menuFolder = Join-Path ([IO.Path]::GetFullPath($programs)) 'WP Store Manager'
    [void][IO.Directory]::CreateDirectory($menuFolder)
    # Keep the .lnk file name ASCII. Windows PowerShell 5.1 / WSH may fail to save a
    # shortcut whose *path* contains Persian characters on some regional/code-page setups.
    $shortcutPath = Join-Path $menuFolder 'Local Bridge Settings.lnk'
    $powershell = Join-Path $PSHOME 'powershell.exe'
    if (-not (Test-Path -LiteralPath $powershell -PathType Leaf)) { throw 'Windows PowerShell 5.1 پیدا نشد.' }

    $shell = $null
    $shortcut = $null
    try {
        $shell = New-Object -ComObject WScript.Shell
        $shortcut = $shell.CreateShortcut($shortcutPath)
        $shortcut.TargetPath = $powershell
        $shortcut.Arguments = '-NoLogo -NoProfile -STA -ExecutionPolicy Bypass -File ' + (Quote-ProcessArgument $installer) + ' -Configure'
        $shortcut.WorkingDirectory = $script:InstallRoot
        $shortcut.Description = 'تنظیم و آزمون اتصال پل محلی WP Store Manager'
        $shortcut.Save()
        if (-not (Test-Path -LiteralPath $shortcutPath -PathType Leaf)) { throw 'فایل میانبر پس از ذخیره ایجاد نشد.' }
    } finally {
        if ($shortcut -and [Runtime.InteropServices.Marshal]::IsComObject($shortcut)) {
            try { [void][Runtime.InteropServices.Marshal]::FinalReleaseComObject($shortcut) } catch { }
        }
        if ($shell -and [Runtime.InteropServices.Marshal]::IsComObject($shell)) {
            try { [void][Runtime.InteropServices.Marshal]::FinalReleaseComObject($shell) } catch { }
        }
    }
    return $shortcutPath
}
function Get-DependencyInstallAdvice([string]$Message) {
    $text = [string]$Message
    if ($text -match '(?i)(permissionerror|permission denied|access is denied|access denied|winerror\s*5|unauthorizedaccess|errno\s*13)') {
        return 'Pip به خطای دسترسی نوشتن برخورد کرد. اگر پوشهٔ محافظت‌شده‌ای انتخاب کرده‌اید، مسیر نصب را به مسیر پیش‌فرض داخل LocalAppData تغییر دهید؛ اگر باید در همان پوشه نصب شود، روی install-windows.cmd راست‌کلیک و Run as Administrator را انتخاب کنید. مسیر فعلی داخل AppData معمولاً به Administrator نیاز ندارد.'
    }
    if ($text -match '(?i)(readtimeout|connecttimeout|timed out|timeout|proxyerror|connectionerror|name resolution|temporary failure|certificate_verify_failed|certificate verify failed|sslerror|could not fetch url|connection reset|network is unreachable|no route to host)') {
        return 'اتصال pip به PyPI یا سرور فایل‌ها ناموفق بود. اینترنت، DNS، پراکسی و محدودیت فایروال را بررسی کنید و دوباره تلاش کنید؛ نصب در محیط مجازی کاربر انجام می‌شود و به Administrator نیاز ندارد.'
    }
    if ($text -match '(?i)(no matching distribution|could not find a version that satisfies|not a supported wheel|requires-python)') {
        return 'یکی از بسته‌ها با نسخه/معماری Python انتخاب‌شده یا مخزن pip سازگار نیست. Python نسخهٔ 3.10 یا جدیدتر را انتخاب کنید و گزارش کامل را برای تشخیص نام بسته بررسی کنید.'
    }
    return 'نصب pip یکی از بسته‌ها را کامل نکرد. Python و محیط مجازی ساخته شده‌اند؛ خطای دقیق، نام بسته و خروجی pip را با دکمهٔ «گزارش نصب» ببینید. اجرای Administrator را فقط وقتی انتخاب کنید که گزارش واقعاً Access denied/Permission denied نشان دهد.'
}

function Install-Bridge {
    if (Test-Path -LiteralPath $script:InstallLog -PathType Leaf) {
        $previousLog = Join-Path $env:TEMP ('wsm-bridge-install-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff') + '.log')
        try { Move-Item -LiteralPath $script:InstallLog -Destination $previousLog -Force } catch { }
    }
    $script:InstallRoot = [IO.Path]::GetFullPath($script:txtFolder.Text.Trim())
    $script:VenvPython = Join-Path $script:InstallRoot '.venv\Scripts\python.exe'
    $script:BridgeScript = Join-Path $script:InstallRoot 'wsm_local_bridge.py'
    New-Item -ItemType Directory -Path $script:InstallRoot -Force | Out-Null
    foreach ($name in @('wsm_local_bridge.py','requirements.txt','README-FA.md','install-windows.ps1')) {
        $source = Join-Path $script:SourceRoot $name
        $target = Join-Path $script:InstallRoot $name
        if ((Test-Path -LiteralPath $source) -and ([IO.Path]::GetFullPath($source) -ne [IO.Path]::GetFullPath($target))) {
            Copy-Item -LiteralPath $source -Destination $target -Force
        }
    }
    Ensure-Python
    if (-not (Test-Path $script:VenvPython)) {
        Set-Status 'در حال ساخت محیط جداگانهٔ برنامه…'
        $venvArgs = @($script:PythonPrefix) + @('-m','venv',(Join-Path $script:InstallRoot '.venv'))
        Invoke-Native $script:PythonExe $venvArgs 'ساخت محیط Python' | Out-Null
    }
    if (-not (Test-Path $script:VenvPython)) { throw 'ساخت محیط Python کامل نشد.' }
    $script:OptionalHardwareInstallError = $null
    $script:OptionalHardwareInstallAdvice = $null
    Set-Status 'در حال نصب وابستگی‌های اختیاری سخت‌افزاری؛ در صورت اختلال شبکه چند بار تلاش می‌شود…'
    try {
        Invoke-Native $script:VenvPython @('-m','pip','install','--disable-pip-version-check','--retries','5','--timeout','45','--progress-bar','off','--no-input','-r',(Join-Path $script:InstallRoot 'requirements.txt')) 'نصب وابستگی‌های سخت‌افزاری پل' | Out-Null
    } catch {
        $script:OptionalHardwareInstallError = $_.Exception.Message
        $script:OptionalHardwareInstallAdvice = Get-DependencyInstallAdvice $script:OptionalHardwareInstallError
        Set-Status 'نصب پایه ادامه دارد؛ برای وابستگی‌های اختیاری، گزارش pip را در پایان بررسی کنید…'
    }
    $script:SettingsShortcutPath = $null
    $script:ShortcutCreationWarning = $null
    Set-Status 'در حال ساخت میانبر تنظیمات پس از نصب…'
    try {
        $script:SettingsShortcutPath = Create-SettingsShortcut
    } catch {
        # A Start Menu shortcut is a convenience, not a prerequisite for installing or running the bridge.
        $script:ShortcutCreationWarning = $_.Exception.Message
        try { Add-Content -LiteralPath $script:InstallLog -Value ('Start Menu shortcut warning: ' + $script:ShortcutCreationWarning) -Encoding UTF8 } catch { }
        Set-Status 'میانبر تنظیمات ساخته نشد؛ نصب اصلی ادامه دارد…'
    }
    Set-Status 'در حال شروع پل و آماده‌سازی برای جفت‌سازی از اپ…'
    $script:BridgeAutoStartResult = Enable-BridgeTask
}
function Enable-BridgeTask {
    if (-not (Test-Path -LiteralPath $script:VenvPython)) { throw 'محیط Python نصب نشده است.' }
    $taskName = 'WP Store Manager Local Bridge'
    $arguments = (Quote-ProcessArgument $script:BridgeScript) + ' run'
    try {
        $action = New-ScheduledTaskAction -Execute $script:VenvPython -Argument $arguments -WorkingDirectory $script:InstallRoot
        $trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Description 'همگام‌سازی امن WP Store Manager' -RunLevel Limited -Force | Out-Null
        Start-ScheduledTask -TaskName $taskName
        return 'پل برای شروع در ورود بعدی و همین حالا زمان‌بندی شد.'
    } catch {
        $startup = [Environment]::GetFolderPath([Environment+SpecialFolder]::Startup)
        $shell = New-Object -ComObject WScript.Shell
        $shortcut = $shell.CreateShortcut((Join-Path $startup 'WP Store Manager Bridge.lnk'))
        $shortcut.TargetPath = $script:VenvPython
        $shortcut.Arguments = $arguments
        $shortcut.WorkingDirectory = $script:InstallRoot
        $shortcut.Save()
        Start-Process -FilePath $script:VenvPython -ArgumentList $arguments -WorkingDirectory $script:InstallRoot -WindowStyle Hidden
        return 'پل اکنون اجرا شد و میانبر اجرای خودکار هنگام ورود ساخته شد.'
    }
}
function Disable-BridgeTask {
    $taskName = 'WP Store Manager Local Bridge'
    try { Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue } catch { }
    try { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue } catch { }
    # The non-admin fallback starts a hidden process from the current user's Startup folder.
    try {
        $expectedPython = [IO.Path]::GetFullPath($script:VenvPython)
        $expectedScript = [IO.Path]::GetFullPath($script:BridgeScript)
        Get-CimInstance -ClassName Win32_Process -ErrorAction SilentlyContinue |
            Where-Object { $_.ExecutablePath -and $_.ExecutablePath -ieq $expectedPython -and $_.CommandLine -like ('*' + $expectedScript + '* run*') } |
            ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    } catch { }
    $startup = [Environment]::GetFolderPath([Environment+SpecialFolder]::Startup)
    $startupLink = Join-Path $startup 'WP Store Manager Bridge.lnk'
    if (Test-Path -LiteralPath $startupLink) { Remove-Item -LiteralPath $startupLink -Force -ErrorAction SilentlyContinue }
}

function Show-BridgeSettings {
    if (-not (Test-Path -LiteralPath $script:VenvPython) -or -not (Test-Path -LiteralPath $script:BridgeScript)) {
        [System.Windows.Forms.MessageBox]::Show('فایل‌های پل یا محیط Python پیدا نشدند. نصب‌گر را دوباره اجرا کنید.','نصب ناقص','OK','Error') | Out-Null
        return
    }
    $form = New-Object System.Windows.Forms.Form
    $form.Text = 'تنظیمات پل محلی WP Store Manager'
    $form.ClientSize = New-Object System.Drawing.Size(790,710)
    $form.StartPosition = 'CenterScreen'
    $form.FormBorderStyle = 'FixedDialog'
    $form.MaximizeBox = $false
    $form.MinimizeBox = $true
    $form.RightToLeft = 'Yes'
    $form.RightToLeftLayout = $true
    $form.Font = New-Object System.Drawing.Font('Segoe UI',9)
    $form.BackColor = [System.Drawing.Color]::FromArgb(248,250,252)

    $heading = New-UiLabel 'تنظیم و آزمون پس از نصب' 18 10 750 32 15
    $heading.Font = New-Object System.Drawing.Font('Segoe UI',14,[System.Drawing.FontStyle]::Bold)
    [void]$form.Controls.Add($heading)

    $storeBox = New-Object System.Windows.Forms.GroupBox
    $storeBox.Text = '۱) جفت‌سازی امن WordPress از اپ دسکتاپ'
    $storeBox.Location = New-Object System.Drawing.Point(18,48)
    $storeBox.Size = New-Object System.Drawing.Size(754,230)
    [void]$form.Controls.Add($storeBox)
    [void]$storeBox.Controls.Add((New-UiLabel 'نشانی REST API ذخیره‌شده (نمایشی؛ اتصال تازه از اپ انجام می‌شود)' 16 25 710 22 9))
    $script:settingsApi = New-UiTextBox 20 47 710 28 '—'
    $script:settingsApi.ReadOnly = $true
    [void]$storeBox.Controls.Add($script:settingsApi)
    [void]$storeBox.Controls.Add((New-UiLabel 'در نسخهٔ فعلی، Access Key فقط ورود اولیه را آغاز می‌کند و این پل را جفت نمی‌کند.' 16 81 710 30 9))
    $script:btnConnectStore = New-UiButton 'راهنمای جفت‌سازی امن' 20 145 165 34
    $script:btnTestStore = New-UiButton 'آزمون نشست ذخیره‌شده' 192 145 145 34
    [void]$storeBox.Controls.Add($script:btnConnectStore)
    [void]$storeBox.Controls.Add($script:btnTestStore)
    $script:settingsStoreStatus = New-UiStatusBox 350 143 380 44
    $script:settingsStoreStatus.Text = 'برای اتصال تازه، از اپ دسکتاپ و حساب مدیر استفاده کنید؛ Access Key لازم نیست.'
    [void]$storeBox.Controls.Add($script:settingsStoreStatus)
    [void]$storeBox.Controls.Add((New-UiLabel 'اپ دسکتاپ ← «اتصال دستگاه» ← «جفت‌سازی امن با WordPress»؛ سپس مجوز club.bridge را برای دستگاه تازه در پیشخوان فعال کنید. این پنجره فقط نشست‌های قدیمی را می‌آزماید.' 18 182 710 38 8))

    $deviceBox = New-Object System.Windows.Forms.GroupBox
    $deviceBox.Text = '۲) دستگاه واقعی ZKTeco در شبکهٔ باشگاه'
    $deviceBox.Location = New-Object System.Drawing.Point(18,288)
    $deviceBox.Size = New-Object System.Drawing.Size(754,238)
    [void]$form.Controls.Add($deviceBox)
    [void]$deviceBox.Controls.Add((New-UiLabel 'IP خصوصی' 16 25 300 22 9))
    $script:settingsHost = New-UiTextBox 20 47 300 28 '192.168.1.201'
    [void]$deviceBox.Controls.Add($script:settingsHost)
    [void]$deviceBox.Controls.Add((New-UiLabel 'پورت' 330 25 92 22 9))
    $script:settingsPort = New-Object System.Windows.Forms.NumericUpDown
    $script:settingsPort.Location = New-Object System.Drawing.Point(334,47)
    $script:settingsPort.Size = New-Object System.Drawing.Size(90,28)
    $script:settingsPort.Minimum = 1
    $script:settingsPort.Maximum = 65535
    $script:settingsPort.Value = 4370
    [void]$deviceBox.Controls.Add($script:settingsPort)
    [void]$deviceBox.Controls.Add((New-UiLabel 'رمز ارتباط' 440 25 160 22 9))
    $script:settingsZkPassword = New-UiTextBox 444 47 160 28 '0'
    $script:settingsZkPassword.UseSystemPasswordChar = $true
    [void]$deviceBox.Controls.Add($script:settingsZkPassword)
    [void]$deviceBox.Controls.Add((New-UiLabel 'نام دستگاه' 16 81 300 22 9))
    $script:settingsZkName = New-UiTextBox 20 103 300 28 'ZKTeco'
    [void]$deviceBox.Controls.Add($script:settingsZkName)
    [void]$deviceBox.Controls.Add((New-UiLabel 'جهت رویداد' 330 81 270 22 9))
    $script:settingsDirection = New-Object System.Windows.Forms.ComboBox
    $script:settingsDirection.Location = New-Object System.Drawing.Point(334,103)
    $script:settingsDirection.Size = New-Object System.Drawing.Size(270,28)
    $script:settingsDirection.DropDownStyle = [System.Windows.Forms.ComboBoxStyle]::DropDownList
    [void]$script:settingsDirection.Items.Add('in')
    [void]$script:settingsDirection.Items.Add('out')
    $script:settingsDirection.SelectedIndex = 0
    [void]$deviceBox.Controls.Add($script:settingsDirection)
    $script:btnTestZk = New-UiButton 'تست واقعی دستگاه' 20 144 150 34
    $script:btnSaveZk = New-UiButton 'ذخیرهٔ دستگاه' 180 144 145 34
    [void]$deviceBox.Controls.Add($script:btnTestZk)
    [void]$deviceBox.Controls.Add($script:btnSaveZk)
    $script:settingsZkStatus = New-UiStatusBox 338 142 390 42
    $script:settingsZkStatus.Text = 'دستگاه هنوز آزمایش نشده است.'
    [void]$deviceBox.Controls.Add($script:settingsZkStatus)
    [void]$deviceBox.Controls.Add((New-UiLabel 'تست به دستگاه واقعی وصل می‌شود و فقط لاگ تردد را می‌خواند. شناسهٔ کاربر ترمینال باید با device_user_id عضو در باشگاه برابر باشد؛ انتقال اثرانگشت/کنترل رله انجام نمی‌شود و سازگاری مدل/Firmware باید آزموده شود.' 18 183 710 39 8))

    $runBox = New-Object System.Windows.Forms.GroupBox
    $runBox.Text = '۳) همگام‌سازی خودکار'
    $runBox.Location = New-Object System.Drawing.Point(18,536)
    $runBox.Size = New-Object System.Drawing.Size(754,104)
    [void]$form.Controls.Add($runBox)
    $script:btnStartBridge = New-UiButton 'شروع همگام‌سازی' 20 31 160 34
    $script:btnStopBridge = New-UiButton 'توقف همگام‌سازی' 190 31 150 34
    [void]$runBox.Controls.Add($script:btnStartBridge)
    [void]$runBox.Controls.Add($script:btnStopBridge)
    $script:settingsStatus = New-UiStatusBox 355 25 375 52
    $script:settingsStatus.Text = 'پس از ذخیرهٔ اتصال و دستگاه، همگام‌سازی را شروع کنید.'
    [void]$runBox.Controls.Add($script:settingsStatus)

    $script:settingsClose = New-UiButton 'بستن' 632 652 100 34
    [void]$form.Controls.Add($script:settingsClose)

    $configPath = Join-Path $env:LOCALAPPDATA 'WPStoreManagerBridge\config.json'
    if (Test-Path -LiteralPath $configPath) {
        try {
            $saved = Get-Content -LiteralPath $configPath -Raw -Encoding UTF8 | ConvertFrom-Json
            if ($saved.api_base) { $script:settingsApi.Text = [string]$saved.api_base }
            $savedDevices = @()
            if ($saved.zkteco_devices) { $savedDevices = @($saved.zkteco_devices) }
            if ($savedDevices.Count -gt 0) {
                $savedZk = $savedDevices[0]
                $script:settingsHost.Text = [string]$savedZk.host
                $script:settingsPort.Value = [Math]::Max(1,[Math]::Min(65535,[int]$savedZk.port))
                $script:settingsZkPassword.Text = [string]$savedZk.password
                $script:settingsZkName.Text = [string]$savedZk.name
                $script:settingsDirection.SelectedItem = [string]$savedZk.direction
                if ($script:settingsDirection.SelectedIndex -lt 0) { $script:settingsDirection.SelectedIndex = 0 }
            }
            $script:settingsStoreStatus.Text = 'پیکربندی محلی پیدا شد؛ نشست موجود را فقط برای عیب‌یابی آزمایش کنید.'
        } catch {
            $script:settingsStoreStatus.Text = 'خواندن تنظیمات محلی ناموفق بود؛ اطلاعات را دوباره ذخیره کنید.'
        }
    }

    $script:btnConnectStore.Add_Click({
        $message = 'اتصال تازه در نسخهٔ فعلی با Access Key از این پنجره انجام نمی‌شود. اپ WP Store Manager Desktop را روی همین رایانه باز کنید، با حساب مدیر وارد شوید و «اتصال دستگاه ← جفت‌سازی امن با WordPress» را بزنید.'
        $script:settingsStoreStatus.Text = $message
        Set-SettingsStatus 'برای جفت‌سازی تازه از اپ دسکتاپ استفاده کنید؛ Access Key در این پنجره لازم نیست.'
    })
    $script:btnTestStore.Add_Click({
        try {
            Set-SettingsStatus 'در حال آزمون نشست و امضای امن WordPress…'
            $result = Invoke-Native $script:VenvPython @($script:BridgeScript,'test-store') 'آزمون REST فروشگاه'
            $script:settingsStoreStatus.Text = $result
            Set-SettingsStatus 'آزمون اتصال فروشگاه تمام شد.'
        } catch {
            $script:settingsStoreStatus.Text = $_.Exception.Message
            Set-SettingsStatus 'آزمون نشست قبلی ناموفق بود؛ جفت‌سازی امن را از اپ دسکتاپ دوباره انجام دهید.'
        }
    })
    $script:btnTestZk.Add_Click({
        try {
            if (-not (Test-PrivateDeviceIP $script:settingsHost.Text)) { throw 'IP خصوصی معتبر در شبکهٔ باشگاه وارد کنید.' }
            Set-SettingsStatus 'در حال اتصال مستقیم به دستگاه و خواندن گزارش تردد…'
            $payload = @{host=$script:settingsHost.Text.Trim();port=[int]$script:settingsPort.Value;password=$script:settingsZkPassword.Text.Trim();name=(Get-DefaultText $script:settingsZkName.Text $script:settingsHost.Text.Trim());direction=[string]$script:settingsDirection.SelectedItem}
            $result = Invoke-NativeJson $script:VenvPython @($script:BridgeScript,'test-zk','--stdin-json') $payload 'آزمون واقعی ZKTeco'
            $script:settingsZkStatus.Text = $result
            Set-SettingsStatus 'آزمون دستگاه تمام شد؛ اگر موفق بود، دستگاه را ذخیره کنید.'
        } catch {
            $script:settingsZkStatus.Text = $_.Exception.Message
            Set-SettingsStatus 'ارتباط با دستگاه برقرار نشد؛ IP، پورت، رمز، شبکه و سازگاری مدل را بررسی کنید.'
        }
    })
    $script:btnSaveZk.Add_Click({
        try {
            if (-not (Test-PrivateDeviceIP $script:settingsHost.Text)) { throw 'IP خصوصی معتبر در شبکهٔ باشگاه وارد کنید.' }
            $payload = @{host=$script:settingsHost.Text.Trim();port=[int]$script:settingsPort.Value;password=$script:settingsZkPassword.Text.Trim();name=(Get-DefaultText $script:settingsZkName.Text $script:settingsHost.Text.Trim());direction=[string]$script:settingsDirection.SelectedItem}
            $result = Invoke-NativeJson $script:VenvPython @($script:BridgeScript,'add-zk','--stdin-json') $payload 'ذخیرهٔ دستگاه ZKTeco'
            $script:settingsZkStatus.Text = $result
            Set-SettingsStatus 'تنظیم دستگاه به‌صورت محلی و در فایل محرمانه ذخیره شد.'
        } catch {
            $script:settingsZkStatus.Text = $_.Exception.Message
            Set-SettingsStatus 'ذخیرهٔ دستگاه انجام نشد.'
        }
    })
    $script:btnStartBridge.Add_Click({
        try {
            Set-SettingsStatus 'در حال شروع یا بازیابی پل محلی…'
            $taskResult = Enable-BridgeTask
            Set-SettingsStatus ($taskResult + "`r`nتا جفت‌سازی امن در پنل اپ، سرویس محلی در حالت انتظار می‌ماند؛ Access Key لازم نیست وارد کنید.")
        } catch {
            Set-SettingsStatus $_.Exception.Message
        }
    })
    $script:btnStopBridge.Add_Click({
        Disable-BridgeTask
        Set-SettingsStatus 'اجرای خودکار پل متوقف شد؛ اطلاعات اتصال و دستگاه حفظ شده‌اند.'
    })
    $script:settingsClose.Add_Click({ $form.Close() })
    [void]$form.ShowDialog()
}

function Show-Installer {
    $form = New-Object System.Windows.Forms.Form
    $form.Text = 'نصب پل محلی WP Store Manager'
    $form.ClientSize = New-Object System.Drawing.Size(680,500)
    $form.StartPosition = 'CenterScreen'
    $form.FormBorderStyle = 'FixedDialog'
    $form.MaximizeBox = $false
    $form.MinimizeBox = $true
    $form.RightToLeft = 'Yes'
    $form.RightToLeftLayout = $true
    $form.Font = New-Object System.Drawing.Font('Segoe UI',10)
    $form.BackColor = [System.Drawing.Color]::FromArgb(248,250,252)

    $header = New-Object System.Windows.Forms.Panel
    $header.Location = New-Object System.Drawing.Point(0,0)
    $header.Size = New-Object System.Drawing.Size(680,82)
    $header.BackColor = [System.Drawing.Color]::FromArgb(15,35,58)
    $title = New-UiLabel 'راه‌اندازی پل محلی باشگاه' 24 10 620 34 16
    $title.ForeColor = [System.Drawing.Color]::White
    $title.Font = New-Object System.Drawing.Font('Segoe UI',15,[System.Drawing.FontStyle]::Bold)
    $subtitle = New-UiLabel 'نصب مرحله‌ای، انتخاب پوشه و پیکربندی جداگانه پس از نصب' 24 45 620 24 9
    $subtitle.ForeColor = [System.Drawing.Color]::FromArgb(190,210,230)
    $header.Controls.AddRange(@($title,$subtitle))
    [void]$form.Controls.Add($header)

    $pages = @()
    for ($i=0; $i -lt 3; $i++) {
        $panel = New-Object System.Windows.Forms.Panel
        $panel.Location = New-Object System.Drawing.Point(24,100)
        $panel.Size = New-Object System.Drawing.Size(632,330)
        $panel.Visible = $false
        [void]$form.Controls.Add($panel)
        $pages += $panel
    }

    # Step 1: folder and optional explicit interpreter path.
    $p0 = $pages[0]
    [void]$p0.Controls.Add((New-UiLabel 'محل نصب' 10 4 600 30 13))
    [void]$p0.Controls.Add((New-UiLabel 'یک پوشه/درایو انتخاب کنید. برای نصب معمولی دسترسی Administrator لازم نیست.' 10 37 600 36 9))
    $script:txtFolder = New-UiTextBox 24 76 460 30 $script:InstallRoot
    $script:btnBrowseFolder = New-UiButton 'انتخاب پوشه…' 496 75 126 32
    [void]$p0.Controls.Add($script:txtFolder)
    [void]$p0.Controls.Add($script:btnBrowseFolder)
    [void]$p0.Controls.Add((New-UiLabel 'Python 3.10+ (اختیاری؛ در صورت پیدا نشدن، مسیر python.exe را انتخاب کنید)' 10 123 600 25 9))
    $script:txtPythonExe = New-UiTextBox 24 149 460 30 ''
    $script:btnBrowsePython = New-UiButton 'انتخاب python.exe…' 496 148 126 32
    [void]$p0.Controls.Add($script:txtPythonExe)
    [void]$p0.Controls.Add($script:btnBrowsePython)
    [void]$p0.Controls.Add((New-UiLabel 'تشخیص خودکار py/python/python3، PATH، رجیستری و نصب‌های متداول را بررسی می‌کند. اگر Python از CMD پیدا می‌شود ولی از wizard نه، فایل python.exe را همین‌جا انتخاب کنید.' 10 190 600 58 9))
    [void]$p0.Controls.Add((New-UiLabel 'اطلاعات دستگاه حضور و غیاب در نصب پرسیده نمی‌شود. جفت‌سازی تازهٔ فروشگاه را بعداً از داخل اپ WP Store Manager Desktop انجام دهید؛ میانبر Local Bridge Settings فقط برای آزمون نشست‌های قدیمی است.' 10 253 600 60 9))

    # Step 2: review; credentials and device details are intentionally not requested here.
    $p1 = $pages[1]
    [void]$p1.Controls.Add((New-UiLabel 'آمادهٔ نصب' 10 4 600 30 13))
    [void]$p1.Controls.Add((New-UiLabel 'این wizard برنامه، Python و کتابخانه‌های اختیاری سخت‌افزاری (PyZK، Modbus، Serial، ESC/POS و USB) را داخل پوشهٔ انتخاب‌شده نصب می‌کند. نصب کتابخانه‌ها به اینترنت/PyPI نیاز دارد، نه Administrator.' 10 43 600 48 10))
    [void]$p1.Controls.Add((New-UiLabel '• جفت‌سازی تازه را در اپ WP Store Manager Desktop با حساب مدیر و گزینهٔ «اتصال دستگاه ← جفت‌سازی امن با WordPress» انجام دهید؛ Access Key دستی لازم نیست.' 10 101 600 36 9))
    [void]$p1.Controls.Add((New-UiLabel '• IP، پورت و رمز دستگاه را بعد از نصب در همان تنظیمات وارد کنید؛ «تست واقعی دستگاه» اتصال LAN و خواندن گزارش تردد را می‌آزماید.' 10 144 600 52 9))
    [void]$p1.Controls.Add((New-UiLabel '• اگر pip نتواند بسته‌ها را دریافت کند، نصب پایه ادامه می‌یابد؛ گزارش دقیق و راهنمای رفع مشکل در پایان نصب نمایش داده می‌شود. نصب پیش‌فرض در LocalAppData نیاز به Administrator ندارد.' 10 203 600 52 9))
    [void]$p1.Controls.Add((New-UiLabel 'سازگاری هر مدل/Firmware باید جداگانه آزمایش شود. قالب خام اثرانگشت جابه‌جا نمی‌شود؛ بازشدن Modbus gate پیش‌فرض خاموش و فقط پس از پاسخ مجاز WordPress است.' 10 262 600 54 9))

    # Step 3: progress/results.
    $p2 = $pages[2]
    [void]$p2.Controls.Add((New-UiLabel 'نصب' 10 4 600 30 13))
    $script:statusLabel = New-UiLabel 'در حال آماده‌سازی…' 10 48 600 42 10
    [void]$p2.Controls.Add($script:statusLabel)
    $script:progress = New-Object System.Windows.Forms.ProgressBar
    $script:progress.Location = New-Object System.Drawing.Point(20,98)
    $script:progress.Size = New-Object System.Drawing.Size(590,22)
    $script:progress.Style = 'Marquee'
    $script:progress.MarqueeAnimationSpeed = 28
    [void]$p2.Controls.Add($script:progress)
    $script:resultLabel = New-UiLabel '' 10 137 600 190 10
    $script:resultLabel.TextAlign = [System.Drawing.ContentAlignment]::TopRight
    [void]$p2.Controls.Add($script:resultLabel)

    $footer = New-Object System.Windows.Forms.Panel
    $footer.Location = New-Object System.Drawing.Point(0,442)
    $footer.Size = New-Object System.Drawing.Size(680,58)
    $footer.BackColor = [System.Drawing.Color]::FromArgb(238,242,247)
    $script:btnBack = New-UiButton 'قبلی' 24 12 90 32
    $script:btnNext = New-UiButton 'بعدی' 122 12 90 32
    $script:btnInstall = New-UiButton 'نصب' 122 12 90 32
    $script:btnSettings = New-UiButton 'بازکردن تنظیمات' 224 12 142 32
    $script:btnFinish = New-UiButton 'پایان' 376 12 90 32
    $script:btnLog = New-UiButton 'گزارش نصب' 468 12 88 32
    $script:btnCancel = New-UiButton 'لغو' 560 12 90 32
    $script:btnInstall.BackColor = [System.Drawing.Color]::FromArgb(45,190,165)
    $script:btnNext.BackColor = [System.Drawing.Color]::FromArgb(45,190,165)
    $script:btnSettings.Visible = $false
    $script:btnLog.Visible = $false
    $script:btnFinish.Visible = $false
    [void]$footer.Controls.AddRange(@($script:btnBack,$script:btnNext,$script:btnInstall,$script:btnSettings,$script:btnFinish,$script:btnLog,$script:btnCancel))
    [void]$form.Controls.Add($footer)

    function Show-Step([int]$Index) {
        $script:Step = $Index
        for ($i=0; $i -lt $pages.Count; $i++) { $pages[$i].Visible = ($i -eq $Index) }
        $script:btnBack.Visible = ($Index -eq 1)
        $script:btnBack.Enabled = ($Index -eq 1)
        $script:btnNext.Visible = ($Index -eq 0)
        $script:btnInstall.Visible = ($Index -eq 1)
        $script:btnSettings.Visible = ($Index -eq 2 -and $script:btnInstall.Tag -eq 'success')
        if ($Index -lt 2) { $script:btnLog.Visible = $false }
        $script:btnFinish.Visible = ($Index -eq 2)
        $script:btnCancel.Visible = ($Index -lt 2 -or $script:btnInstall.Tag -eq 'error')
        if ($Index -lt 2) { $form.Text = "راه‌اندازی — مرحلهٔ $($Index+1) از ۲" }
        else { $form.Text = 'نصب پل محلی WP Store Manager' }
    }
    function Test-InstallerPage([int]$Index) {
        if ($Index -eq 0) {
            if ([string]::IsNullOrWhiteSpace($script:txtFolder.Text)) { [System.Windows.Forms.MessageBox]::Show('پوشهٔ نصب را انتخاب کنید.','نیاز به ورودی','OK','Warning') | Out-Null; return $false }
            try { [void][IO.Path]::GetFullPath($script:txtFolder.Text.Trim()) } catch { [System.Windows.Forms.MessageBox]::Show('مسیر نصب معتبر نیست.','خطا','OK','Error') | Out-Null; return $false }
            if (-not [string]::IsNullOrWhiteSpace($script:txtPythonExe.Text)) {
                $probe = Test-PythonExecutable $script:txtPythonExe.Text.Trim() @()
                if (-not $probe) { [System.Windows.Forms.MessageBox]::Show('فایل انتخاب‌شده باید با «python.exe --version» نسخهٔ 3.10 یا جدیدتر را گزارش کند.','Python نامعتبر','OK','Warning') | Out-Null; return $false }
                $script:txtPythonExe.Text = $probe.Resolved
            }
        }
        return $true
    }

    $script:btnBrowseFolder.Add_Click({
        $dialog = New-Object System.Windows.Forms.FolderBrowserDialog
        $dialog.Description = 'محل نصب را انتخاب کنید'
        $dialog.SelectedPath = $script:txtFolder.Text
        $dialog.ShowNewFolderButton = $true
        if ($dialog.ShowDialog($form) -eq [System.Windows.Forms.DialogResult]::OK) { $script:txtFolder.Text = $dialog.SelectedPath }
    })
    $script:btnBrowsePython.Add_Click({
        $dialog = New-Object System.Windows.Forms.OpenFileDialog
        $dialog.Title = 'فایل python.exe نسخهٔ 3.10 یا جدیدتر را انتخاب کنید'
        $dialog.Filter = 'Python executable (python.exe)|python.exe|همهٔ فایل‌ها (*.*)|*.*'
        $dialog.CheckFileExists = $true
        if ($dialog.ShowDialog($form) -eq [System.Windows.Forms.DialogResult]::OK) {
            $probe = Test-PythonExecutable $dialog.FileName @()
            if ($probe) { $script:txtPythonExe.Text = $probe.Resolved }
            else { [System.Windows.Forms.MessageBox]::Show('پاسخ «python.exe --version» معتبر نیست یا نسخه کمتر از 3.10 است.','Python نامعتبر','OK','Warning') | Out-Null }
        }
    })
    $script:btnBack.Add_Click({ Show-Step 0 })
    $script:btnNext.Add_Click({ if (Test-InstallerPage 0) { Show-Step 1 } })
    $script:btnCancel.Add_Click({ $form.Close() })
    $script:btnSettings.Add_Click({
        $installer = Join-Path $script:InstallRoot 'install-windows.ps1'
        $powershell = Join-Path $PSHOME 'powershell.exe'
        $processArgs = '-NoLogo -NoProfile -STA -ExecutionPolicy Bypass -File ' + (Quote-ProcessArgument $installer) + ' -Configure'
        Start-Process -FilePath $powershell -ArgumentList $processArgs -WorkingDirectory $script:InstallRoot
    })
    $script:btnLog.Add_Click({
        if (Test-Path -LiteralPath $script:InstallLog -PathType Leaf) {
            Start-Process -FilePath 'notepad.exe' -ArgumentList (Quote-ProcessArgument $script:InstallLog)
        } else {
            [System.Windows.Forms.MessageBox]::Show('گزارش نصب هنوز ساخته نشده است.','گزارش نصب','OK','Information') | Out-Null
        }
    })
    $script:btnFinish.Add_Click({
        if ($script:btnFinish.Tag -eq 'retry') {
            $script:btnFinish.Tag = $null
            $script:btnInstall.Tag = $null
            $script:btnCancel.Text = 'لغو'
            Show-Step 0
            return
        }
        $form.Close()
    })
    $script:btnInstall.Add_Click({
        if (-not (Test-InstallerPage 0)) { Show-Step 0; return }
        $script:btnInstall.Tag = $null
        $script:btnLog.Visible = $false
        Show-Step 2
        $script:btnFinish.Enabled = $false
        $script:statusLabel.Text = 'شروع نصب…'
        $script:progress.Style = 'Marquee'
        $form.Refresh()
        [System.Windows.Forms.Application]::DoEvents()
        try {
            Install-Bridge
            $script:progress.Style = 'Blocks'
            $script:progress.Value = 100
            if ($script:ShortcutCreationWarning) { $script:statusLabel.Text = 'نصب کامل شد؛ میانبر تنظیمات منو ساخته نشد.' }
            elseif ($script:OptionalHardwareInstallError) { $script:statusLabel.Text = 'نصب پایه کامل شد؛ بخشی از وابستگی‌های سخت‌افزاری نصب نشد.' }
            else { $script:statusLabel.Text = 'نصب کامل شد.' }
            $autoStartText = if ($script:BridgeAutoStartResult) { $script:BridgeAutoStartResult } else { 'پل نصب شد و برای اجرا آماده است.' }
            $script:resultLabel.Text = "برنامه در مسیر زیر نصب شد:`r`n$script:InstallRoot`r`n`r`n$autoStartText`r`n`r`nگام بعد: اپ WordPress Store Manager را روی همین رایانه باز کنید، «اتصال دستگاه» را انتخاب و جفت‌سازی امن را تأیید کنید؛ سپس شبکه/USB را اسکن یا دستگاه را دستی پیکربندی کنید. Access Key دستی لازم نیست."
            if ($script:OptionalHardwareInstallError) {
                $manualHardwareCommand = '"' + $script:VenvPython + '" -m pip install --disable-pip-version-check --retries 5 --timeout 45 --verbose -r ' + (Quote-ProcessArgument (Join-Path $script:InstallRoot 'requirements.txt'))
                $advice = if ($script:OptionalHardwareInstallAdvice) { $script:OptionalHardwareInstallAdvice } else { Get-DependencyInstallAdvice $script:OptionalHardwareInstallError }
                $script:resultLabel.Text += "`r`n`r`n$advice`r`nاگر لازم شد این دستور را در CMD اجرا کنید:`r`n$manualHardwareCommand`r`nگزارش کامل: $script:InstallLog"
            }
            if ($script:ShortcutCreationWarning) {
                $script:resultLabel.Text += "`r`n`r`nهشدار: میانبر منوی Start ساخته نشد، اما نصب پل موفق است. برای تنظیمات از همین پنجره دکمهٔ «بازکردن تنظیمات» را بزنید.`r`nجزئیات: $script:ShortcutCreationWarning"
            }
            $script:btnInstall.Tag = 'success'
            $script:btnCancel.Visible = $false
            $script:btnFinish.Text = 'پایان'
            $script:btnFinish.Tag = $null
            $script:btnFinish.Enabled = $true
            $script:btnSettings.Visible = $true
            $script:btnLog.Visible = [bool]($script:OptionalHardwareInstallError -or $script:ShortcutCreationWarning)
        } catch {
            $script:progress.Style = 'Blocks'
            $script:progress.Value = 0
            $script:statusLabel.Text = 'نصب کامل نشد.'
            $script:resultLabel.Text = $_.Exception.Message + "`r`n`r`nگزارش نصب: $script:InstallLog"
            $script:btnInstall.Tag = 'error'
            $script:btnCancel.Visible = $true
            $script:btnCancel.Text = 'بستن'
            $script:btnBack.Visible = $false
            $script:btnFinish.Visible = $true
            $script:btnFinish.Text = 'بازگشت برای اصلاح'
            $script:btnFinish.Tag = 'retry'
            $script:btnFinish.Enabled = $true
            $script:btnSettings.Visible = $false
            $script:btnLog.Visible = Test-Path -LiteralPath $script:InstallLog -PathType Leaf
        }
    })
    $form.Add_Shown({ Show-Step 0 })
    [void]$form.ShowDialog()
}

if ($Configure) { Show-BridgeSettings }
else { Show-Installer }
