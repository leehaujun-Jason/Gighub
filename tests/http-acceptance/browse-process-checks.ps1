# Offline only: shadow both OS query cmdlets with synthetic records in this process.
$ErrorActionPreference='Stop'
$global:GhCleanupMockProcesses=@()
$global:GhCleanupMockListeners=@()
$global:GhCleanupMockQueryFailure=$false
function Get-CimInstance {
    [CmdletBinding()]param([string]$ClassName,[int]$OperationTimeoutSec)
    if($global:GhCleanupMockQueryFailure){throw 'Synthetic query failure'}
    return $global:GhCleanupMockProcesses
}
function Get-NetTCPConnection {
    [CmdletBinding()]param([string]$State)
    if($global:GhCleanupMockQueryFailure){throw 'Synthetic query failure'}
    return $global:GhCleanupMockListeners
}
$checks=0
function Assert-GhCleanup([bool]$value,[string]$label) {
    if(!$value){throw ('Offline process assertion: '+$label)}
    $script:checks++
}
function Observe-GhCleanup {
    $raw=& (Join-Path $PSScriptRoot 'browse-process-observation.ps1') -Run 'accept-0123456789abcdef' -RuntimePid 123 -CleanupPid 777 -ChildPids '124,125'
    return ($raw | ConvertFrom-Json)
}
$value=Observe-GhCleanup
foreach($field in @('runtime_absent','children_absent','php_workers_absent','matching_python_absent','listener_absent','complete')){Assert-GhCleanup ($value.$field -eq $true) ('empty snapshot '+$field)}
$global:GhCleanupMockProcesses=@([pscustomobject]@{ProcessId=123;Name='python.exe';CommandLine='python server.py --run accept-0123456789abcdef'})
$value=Observe-GhCleanup
Assert-GhCleanup (!$value.runtime_absent -and !$value.matching_python_absent) 'live runtime'
$global:GhCleanupMockProcesses=@([pscustomobject]@{ProcessId=123;Name='unrelated.exe';CommandLine='synthetic'})
Assert-GhCleanup (!(Observe-GhCleanup).runtime_absent) 'reused PID is uncertainty'
$global:GhCleanupMockProcesses=@([pscustomobject]@{ProcessId=124;Name='php-cgi.exe';CommandLine='synthetic'})
$value=Observe-GhCleanup
Assert-GhCleanup (!$value.children_absent -and !$value.php_workers_absent) 'live child'
$global:GhCleanupMockProcesses=@([pscustomobject]@{ProcessId=999;Name='php.exe';CommandLine='synthetic'})
Assert-GhCleanup (!(Observe-GhCleanup).php_workers_absent) 'unknown PHP worker'
$global:GhCleanupMockProcesses=@([pscustomobject]@{ProcessId=777;Name='php.exe';CommandLine='synthetic cleanup'})
Assert-GhCleanup ((Observe-GhCleanup).php_workers_absent) 'own cleanup process excluded'
$global:GhCleanupMockProcesses=@([pscustomobject]@{ProcessId=999;Name='python.exe';CommandLine=$null})
Assert-GhCleanup (!(Observe-GhCleanup).matching_python_absent) 'unreadable Python identity'
$global:GhCleanupMockProcesses=@()
foreach($address in @('127.0.0.1','0.0.0.0','::')){
    $global:GhCleanupMockListeners=@([pscustomobject]@{LocalAddress=$address;LocalPort=8765})
    Assert-GhCleanup (!(Observe-GhCleanup).listener_absent) 'active listener'
}
$global:GhCleanupMockListeners=@([pscustomobject]@{LocalAddress='127.0.0.1';LocalPort=3309})
Assert-GhCleanup ((Observe-GhCleanup).listener_absent) 'unrelated port'
$global:GhCleanupMockQueryFailure=$true
Assert-GhCleanup ((Observe-GhCleanup).complete -eq $false) 'query failure is uncertainty'
Write-Output "PASS: $checks offline Windows process-observer checks; OS queries were mocked."
exit 0
