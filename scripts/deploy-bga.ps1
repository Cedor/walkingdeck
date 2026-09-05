[CmdletBinding(SupportsShouldProcess)]
param(
    [string]$HostName = '1.studio.boardgamearena.com',
    [int]$Port = 2022,
    [string]$UserName = 'Cedor',
    [string]$RemotePath = 'thewalkingdeck',
    [string]$PasswordFile,
    [string]$WinScpDllPath,
    [switch]$ForceUpload
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
    'fonts'
    'img'
    'misc'
    'modules'
)
$optionalDirectories = @(
    'misc'
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
$downloadedFiles = @(
    '_ide_helper.php'
    'bga-framework.d.ts'
)
$verifiedFiles = @(
    'gameinfos.inc.php'
    'gameoptions.json'
    'gamepreferences.json'
)

$missingPaths = @(
    ($directories | Where-Object { $_ -notin $optionalDirectories }) + $files |
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
    "Publier $($publishedItems.Count) elements par SFTP, synchroniser les dossiers en miroir et telecharger $($downloadedFiles.Count) fichier(s)"
)) {
    Write-Host 'Elements qui auraient ete publies :'
    $publishedItems | ForEach-Object {
        Write-Host "  $_ -> $(Join-RemotePath $remoteRoot $_)"
    }
    Write-Host 'Elements qui auraient ete telecharges :'
    $downloadedFiles | ForEach-Object {
        Write-Host "  $(Join-RemotePath $remoteRoot $_) -> $_"
    }
    Write-Host 'Tous les fichiers racine seraient compares pour eviter les envois inutiles.'
    if ($ForceUpload) {
        Write-Host 'Confirmation pre-upload ignoree (-ForceUpload).'
    }
    else {
        Write-Host 'Fichiers dont les differences seraient affichees et confirmees :'
        $verifiedFiles | ForEach-Object {
            Write-Host "  $(Join-RemotePath $remoteRoot $_) <-> $_"
        }
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
$comparisonDirectory = $null
$emptyDirectoriesRoot = $null

try {
    Write-Host "Connexion SFTP a $destination..."
    $session.Open($sessionOptions)

    foreach ($file in $downloadedFiles) {
        Write-Host "Telechargement du fichier $file..."
        $result = $session.GetFiles(
            (Join-RemotePath $remoteRoot $file),
            (Join-Path $projectRoot $file),
            $false,
            $transferOptions
        )
        $result.Check()
    }

    $comparisonDirectory = Join-Path `
        ([System.IO.Path]::GetTempPath()) `
        ("walkingdeck-deploy-" + [guid]::NewGuid().ToString('N'))
    $remoteComparisonDirectory = Join-Path $comparisonDirectory 'remote'
    $localComparisonDirectory = Join-Path $comparisonDirectory 'local'
    New-Item -ItemType Directory -Path $remoteComparisonDirectory | Out-Null
    New-Item -ItemType Directory -Path $localComparisonDirectory | Out-Null

    $filesToUpload = @()
    $verifiedDifferentFiles = @()
    Write-Host 'Comparaison des fichiers racine avant publication...'

    foreach ($file in $files) {
        $remotePath = Join-RemotePath $remoteRoot $file
        $remoteCopy = Join-Path $remoteComparisonDirectory $file
        $localCopy = Join-Path $localComparisonDirectory $file
        Copy-Item -LiteralPath (Join-Path $projectRoot $file) -Destination $localCopy

        $remoteFileExists = $session.FileExists($remotePath)
        if ($remoteFileExists) {
            $result = $session.GetFiles(
                $remotePath,
                $remoteCopy,
                $false,
                $transferOptions
            )
            $result.Check()

            $remoteHash = (Get-FileHash -LiteralPath $remoteCopy -Algorithm SHA256).Hash
            $localHash = (Get-FileHash -LiteralPath $localCopy -Algorithm SHA256).Hash
            if ($remoteHash -eq $localHash) {
                Write-Host "  $file : identique, envoi ignore"
                continue
            }
        }
        else {
            [System.IO.File]::WriteAllBytes($remoteCopy, [byte[]]@())
            Write-Host "  $file : absent du serveur, envoi requis" -ForegroundColor Yellow
        }

        $filesToUpload += $file
        if ($remoteFileExists) {
            Write-Host "  $file : different, envoi requis" -ForegroundColor Yellow
        }
        if ($file -in $verifiedFiles) {
            $verifiedDifferentFiles += $file
            if (-not $ForceUpload) {
                Write-Host "`nDifferences pour $file (distant -> local) :" -ForegroundColor Yellow
                & git --no-pager -C $comparisonDirectory diff --no-index --text -- "remote/$file" "local/$file"
                $diffExitCode = $LASTEXITCODE
                $global:LASTEXITCODE = 0
                if ($diffExitCode -notin @(0, 1)) {
                    throw "Impossible d'afficher les differences pour $file (git diff : code $diffExitCode)."
                }
            }
        }
    }

    if ($ForceUpload) {
        Write-Host 'Confirmation pre-upload ignoree (-ForceUpload).'
    }
    elseif ($verifiedDifferentFiles.Count -gt 0) {
        $confirmation = Read-Host `
            "Des differences distantes seront ecrasees pour $($verifiedDifferentFiles -join ', '). Continuer la publication ? [o/N]"
        if ($confirmation -notmatch '^(o|oui|y|yes)$') {
            Write-Host "Publication annulee par l'utilisateur."
            return
        }
    }
    else {
        Write-Host 'Aucune difference detectee sur les fichiers verifies.'
    }

    foreach ($directory in $directories) {
        $localDirectory = Join-Path $projectRoot $directory
        if (-not (Test-Path -LiteralPath $localDirectory -PathType Container)) {
            if ($directory -notin $optionalDirectories) {
                throw "Le dossier local obligatoire $directory est introuvable."
            }

            if (-not $emptyDirectoriesRoot) {
                $emptyDirectoriesRoot = Join-Path `
                    ([System.IO.Path]::GetTempPath()) `
                    ("walkingdeck-empty-" + [guid]::NewGuid().ToString('N'))
                New-Item -ItemType Directory -Path $emptyDirectoriesRoot | Out-Null
            }

            $localDirectory = Join-Path $emptyDirectoriesRoot $directory
            New-Item -ItemType Directory -Path $localDirectory | Out-Null
            Write-Host "Le dossier local optionnel $directory est absent : utilisation d'un dossier vide."
        }

        $remoteDirectory = Join-RemotePath $remoteRoot $directory

        if (-not $session.FileExists($remoteDirectory)) {
            $session.CreateDirectory($remoteDirectory)
        }

        Write-Host "Synchronisation miroir du dossier $directory..."
        $criteria = [WinSCP.SynchronizationCriteria]::Time -bor `
            [WinSCP.SynchronizationCriteria]::Size
        $result = $session.SynchronizeDirectories(
            [WinSCP.SynchronizationMode]::Remote,
            $localDirectory,
            $remoteDirectory,
            $true,
            $true,
            $criteria,
            $transferOptions
        )
        $result.Check()
    }

    foreach ($file in $filesToUpload) {
        Write-Host "Envoi du fichier $file..."
        $result = $session.PutFiles(
            (Join-Path $projectRoot $file),
            (Join-RemotePath $remoteRoot $file),
            $false,
            $transferOptions
        )
        $result.Check()
    }
    if ($filesToUpload.Count -eq 0) {
        Write-Host 'Aucun fichier racine a envoyer.'
    }

    Write-Host 'Publication terminee avec succes.'
}
finally {
    $session.Dispose()
    if ($comparisonDirectory -and (Test-Path -LiteralPath $comparisonDirectory)) {
        Remove-Item -LiteralPath $comparisonDirectory -Recurse -Force
    }
    if ($emptyDirectoriesRoot -and (Test-Path -LiteralPath $emptyDirectoriesRoot)) {
        Remove-Item -LiteralPath $emptyDirectoriesRoot -Recurse -Force
    }
}
