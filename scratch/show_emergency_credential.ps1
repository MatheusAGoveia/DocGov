param([Parameter(Mandatory = $true)][string]$CredentialPath)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
$taskCredential = Import-Clixml -LiteralPath $CredentialPath
if ($taskCredential -isnot [System.Management.Automation.PSCredential]) { throw 'Arquivo de credencial inválido.' }
$taskForm = New-Object System.Windows.Forms.Form
$taskForm.Text = 'DocGov - nova senha de emergência'
$taskForm.Size = New-Object System.Drawing.Size(670, 210)
$taskForm.StartPosition = 'CenterScreen'
$taskForm.TopMost = $true
$taskForm.FormBorderStyle = 'FixedDialog'
$taskForm.MaximizeBox = $false
$taskLabel = New-Object System.Windows.Forms.Label
$taskLabel.Text = $taskCredential.UserName
$taskLabel.Location = New-Object System.Drawing.Point(18, 18)
$taskLabel.Size = New-Object System.Drawing.Size(620, 25)
$taskBox = New-Object System.Windows.Forms.TextBox
$taskBox.Location = New-Object System.Drawing.Point(18, 52)
$taskBox.Size = New-Object System.Drawing.Size(620, 28)
$taskBox.ReadOnly = $true
$taskBox.UseSystemPasswordChar = $true
$taskBox.Text = $taskCredential.GetNetworkCredential().Password
$taskReveal = New-Object System.Windows.Forms.CheckBox
$taskReveal.Text = 'Mostrar senha'
$taskReveal.Location = New-Object System.Drawing.Point(18, 94)
$taskReveal.Size = New-Object System.Drawing.Size(160, 25)
$taskReveal.Add_CheckedChanged({ $taskBox.UseSystemPasswordChar = -not $taskReveal.Checked })
$taskCopy = New-Object System.Windows.Forms.Button
$taskCopy.Text = 'Copiar senha'
$taskCopy.Location = New-Object System.Drawing.Point(190, 94)
$taskCopy.Size = New-Object System.Drawing.Size(125, 28)
$taskCopy.Add_Click({ [System.Windows.Forms.Clipboard]::SetText($taskBox.Text); $taskCopy.Text = 'Copiada' })
$taskClose = New-Object System.Windows.Forms.Button
$taskClose.Text = 'Fechar'
$taskClose.Location = New-Object System.Drawing.Point(510, 94)
$taskClose.Size = New-Object System.Drawing.Size(125, 28)
$taskClose.Add_Click({ $taskForm.Close() })
$taskForm.Controls.AddRange(@($taskLabel, $taskBox, $taskReveal, $taskCopy, $taskClose))
$taskForm.Add_FormClosed({ $taskBox.Clear() })
[void]$taskForm.ShowDialog()
