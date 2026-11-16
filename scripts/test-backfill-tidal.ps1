$ErrorActionPreference = 'Stop'
$failures = 0
$global:tidalTestCaptured = @()
$global:tidalTestDispatchExit = 0
function gh {
    $global:tidalTestCaptured = @($args)
    $global:LASTEXITCODE = $global:tidalTestDispatchExit
}
function Check([string]$Label, [bool]$Ok) {
    if ($Ok) { Write-Output "  ok   $Label" }
    else { Write-Output "  FAIL $Label"; $script:failures++ }
}

& "$PSScriptRoot/backfill-tidal.ps1" -From '2026-10-30' | Out-Null
Check 'dispatches the date through the standalone TIDAL workflow' (($global:tidalTestCaptured -join ' ') -eq 'workflow run tidal-backfill.yml --ref main --field from=2026-10-30 --field check=new')
& "$PSScriptRoot/backfill-tidal.ps1" -From '2026-10-30' -Check missing | Out-Null
Check 'passes a recheck of one status' (($global:tidalTestCaptured -join ' ') -eq 'workflow run tidal-backfill.yml --ref main --field from=2026-10-30 --field check=missing')
$global:tidalTestCaptured = @()
$rejected = $false
try { & "$PSScriptRoot/backfill-tidal.ps1" -From '2026-10-30' -Check everything | Out-Null }
catch { $rejected = $true }
Check 'rejects an unknown check without starting a workflow' ($rejected -and $global:tidalTestCaptured.Count -eq 0)
foreach ($value in @('2026-02-30', '30/10/2026', '2026-10-30;echo unsafe', '9999-01-01')) {
    $global:tidalTestCaptured = @()
    $rejected = $false
    try { & "$PSScriptRoot/backfill-tidal.ps1" -From $value | Out-Null }
    catch { $rejected = $true }
    Check "rejects $value without starting a workflow" ($rejected -and $global:tidalTestCaptured.Count -eq 0)
}
$global:tidalTestDispatchExit = 1
$rejected = $false
try { & "$PSScriptRoot/backfill-tidal.ps1" -From '2026-10-30' | Out-Null }
catch { $rejected = $true }
Check 'reports a failed workflow dispatch' $rejected
if ($failures -gt 0) { throw "$failures test(s) failed." }
Write-Output 'All tests passed.'
