[CmdletBinding(SupportsShouldProcess)]
param(
    [string]$HostName = '1.studio.boardgamearena.com',
    [int]$Port = 2022,
    [string]$UserName = 'Cedor',
    [string]$RemotePath = 'thewalkingdeck',
    [string]$PasswordFile,
    [string]$WinScpDllPath
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
if ([string]::IsNullOrWhiteSpace($PasswordFile)) {
    $PasswordFile = Join-Path $projectRoot '.localDocs\.bga-sftp-password'
}
elseif (-not [System.IO.Path]::IsPathRooted($PasswordFile)) {
    $PasswordFile = Join-Path $projectRoot $PasswordFile
}

$directories = @(
    'doc'
    'img'
    'misc'
    'modules'
)
$files = @(
    'dbmodel.sql'
    'gameinfos.inc.php'
    'gameoptions.json'
    'gamepreferences.json'
    'states.inc.php'
    'stats.json'
    'thewalkingdeck.css'
    'thewalkingdeck.js'
)

$missingPaths = @(
    $directories + $files |
        Where-Object { -not (Test-Path -LiteralPath (Join-Path $projectRoot $_)) }
)
if ($missingPaths.Count -gt 0) {
    throw "Publication annulee. Elements manquants : $($missingPaths -join ', ')"
}

$remoteRoot = $RemotePath.TrimEnd('/')
if ([string]::IsNullOrWhiteSpace($remoteRoot)) {
    $remoteRoot = '/'
}

function Join-RemotePath {
    param(
        [Parameter(Mandatory)][string]$Parent,
        [Parameter(Mandatory)][string]$Child
    )

    if ($Parent -eq '/') {
        return "/$Child"
    }
    return "$Parent/$Child"
}

$destination = "$UserName@$HostName`:$Port/$remoteRoot"
$publishedItems = $directories + $files
if (-not $PSCmdlet.ShouldProcess(
    $destination,
    "Publier $($publishedItems.Count) elements par SFTP"
)) {
    Write-Host 'Elements qui auraient ete publies :'
    $publishedItems | ForEach-Object {
        Write-Host "  $_ -> $(Join-RemotePath $remoteRoot $_)"
    }
    exit 0
}

if (-not $WinScpDllPath) {
    $winScpCandidates = @(
        (Join-Path $PSScriptRoot 'WinSCPnet.dll')
        (Join-Path $projectRoot 'WinSCPnet.dll')
        (Join-Path $env:LOCALAPPDATA 'Programs\WinSCP\WinSCPnet.dll')
        (Join-Path $env:LOCALAPPDATA 'WinSCP\WinSCPnet.dll')
        (Join-Path ${env:ProgramFiles(x86)} 'WinSCP\WinSCPnet.dll')
        (Join-Path $env:ProgramFiles 'WinSCP\WinSCPnet.dll')
    ) | Where-Object { $_ -and (Test-Path -LiteralPath $_) }

    $WinScpDllPath = $winScpCandidates | Select-Object -First 1
}

if (-not $WinScpDllPath -or -not (Test-Path -LiteralPath $WinScpDllPath)) {
    throw @'
WinSCPnet.dll est introuvable.
Installez WinSCP avec "winget install WinSCP.WinSCP", puis relancez ce script.
Vous pouvez aussi fournir son chemin avec -WinScpDllPath.
'@
}

Add-Type -Path (Resolve-Path $WinScpDllPath)

$sessionUserName = $UserName
$securePassword = $null

if (Test-Path -LiteralPath $PasswordFile -PathType Leaf) {
    $password = (Get-Content -LiteralPath $PasswordFile -Raw).TrimEnd([char[]]"`r`n")
    if (-not [string]::IsNullOrEmpty($password)) {
        $securePassword = ConvertTo-SecureString $password -AsPlainText -Force
        $password = $null
    }
}

if ($null -eq $securePassword) {
    $credential = Get-Credential `
        -UserName $UserName `
        -Message "Identifiants SFTP pour $HostName"
    if (-not $credential) {
        throw 'Publication annulee : aucun identifiant fourni.'
    }
    $sessionUserName = $credential.UserName
    $securePassword = $credential.Password
}

$sessionOptions = [WinSCP.SessionOptions]@{
    Protocol = [WinSCP.Protocol]::Sftp
    HostName = $HostName
    PortNumber = $Port
    UserName = $sessionUserName
    SecurePassword = $securePassword
    SshHostKeyPolicy = [WinSCP.SshHostKeyPolicy]::AcceptNew
}

$transferOptions = [WinSCP.TransferOptions]::new()
$transferOptions.TransferMode = [WinSCP.TransferMode]::Binary
$session = [WinSCP.Session]::new()

try {
    Write-Host "Connexion SFTP a $destination..."
    $session.Open($sessionOptions)

    foreach ($directory in $directories) {
        $localDirectory = Join-Path $projectRoot $directory
        $remoteDirectory = Join-RemotePath $remoteRoot $directory

        if (-not $session.FileExists($remoteDirectory)) {
            $session.CreateDirectory($remoteDirectory)
        }

        Write-Host "Envoi du dossier $directory..."
        $result = $session.PutFiles(
            (Join-Path $localDirectory '*'),
            "$remoteDirectory/",
            $false,
            $transferOptions
        )
        $result.Check()
    }

    foreach ($file in $files) {
        Write-Host "Envoi du fichier $file..."
        $result = $session.PutFiles(
            (Join-Path $projectRoot $file),
            (Join-RemotePath $remoteRoot $file),
            $false,
            $transferOptions
        )
        $result.Check()
    }

    Write-Host 'Publication terminee avec succes.'
}
finally {
    $session.Dispose()
}
