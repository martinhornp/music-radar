param(
    [Parameter(Mandatory = $true)]
    [string]$From,
    [ValidateSet('new', 'errors', 'ambiguous', 'missing')]
    [string]$Check = 'new'
)

$ErrorActionPreference = 'Stop'
$parsedDate = [datetime]::MinValue
if (-not [datetime]::TryParseExact($From, 'yyyy-MM-dd', [cultureinfo]::InvariantCulture,
        [Globalization.DateTimeStyles]::None, [ref]$parsedDate)) {
    throw 'From must be a valid YYYY-MM-DD release date.'
}
$today = [TimeZoneInfo]::ConvertTimeFromUtc([datetime]::UtcNow,
    [TimeZoneInfo]::FindSystemTimeZoneById('Romance Standard Time')).Date
if ($parsedDate.Date -gt $today) {
    throw 'From cannot be a future release date.'
}

& gh workflow run tidal-backfill.yml --ref main --field "from=$From" --field "check=$Check"
if ($LASTEXITCODE -ne 0) {
    throw "Could not start the TIDAL backfill (gh exit $LASTEXITCODE)."
}
Write-Output "Requested TIDAL backfill ($Check) from $From, inclusive. Progress is stored on the server."
