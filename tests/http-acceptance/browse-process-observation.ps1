param(
    [Parameter(Mandatory=$true)][ValidatePattern('^accept-[0-9a-f]{16}$')][string]$Run,
    [Parameter(Mandatory=$true)][ValidateRange(1,4294967295)][long]$RuntimePid,
    [Parameter(Mandatory=$true)][ValidateRange(1,4294967295)][long]$CleanupPid,
    [Parameter(Mandatory=$true)][ValidatePattern('^(none|[0-9]+(,[0-9]+)*)$')][string]$ChildPids
)
$ErrorActionPreference='Stop'
try {
    $children=@(); if($ChildPids -ne 'none'){ $children=@($ChildPids.Split(',') | ForEach-Object { [long]$_ }) }
    $processes=@(Get-CimInstance Win32_Process -OperationTimeoutSec 10 -ErrorAction Stop)
    $liveIds=@($processes | ForEach-Object { [long]$_.ProcessId })
    $php=@($processes | Where-Object { $_.Name -match '^php(?:-cgi)?\.exe$' -and [long]$_.ProcessId -ne $CleanupPid })
    $python=@($processes | Where-Object { $_.Name -match '^python(?:w|[0-9.]*)?\.exe$' })
    # An unreadable Python command line is uncertainty, never proof of termination.
    $matching=@($python | Where-Object { [string]::IsNullOrWhiteSpace($_.CommandLine) -or $_.CommandLine.Contains($Run) })
    $listeners=@(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object { $_.LocalPort -eq 8765 })
    [ordered]@{
        runtime_absent=($RuntimePid -notin $liveIds)
        children_absent=(@($children | Where-Object { $_ -in $liveIds }).Count -eq 0)
        php_workers_absent=($php.Count -eq 0)
        matching_python_absent=($matching.Count -eq 0)
        listener_absent=($listeners.Count -eq 0)
        complete=$true
    } | ConvertTo-Json -Compress
} catch {
    '{"complete":false}'
    exit 1
}
