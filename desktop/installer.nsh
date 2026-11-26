!ifmacrondef setDesktopInstallStatus
  !macro setDesktopInstallStatus text
    DetailPrint "${text}"
  !macroend
!endif

!macro customInstall
  !insertmacro setDesktopInstallStatus "Verificando los componentes de Windows..."
  SetRegView 64
  ReadRegDWORD $0 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Installed"
  ReadRegDWORD $1 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Minor"
  ${If} $0 != 1
  ${OrIf} $1 < 44
    !insertmacro setDesktopInstallStatus "Instalando Microsoft Visual C++..."
    ClearErrors
    ExecWait '"$INSTDIR\resources\vc_redist.x64.exe" /install /quiet /norestart' $0
    ${If} ${Errors}
      MessageBox MB_OK|MB_ICONEXCLAMATION "Windows no pudo abrir el instalador de Microsoft Visual C++. Puedes ejecutarlo desde la carpeta resources de Hostal Rincón."
    ${ElseIf} $0 == 3010
      MessageBox MB_OK|MB_ICONINFORMATION "Reinicia Windows antes de abrir Hostal Rincón."
    ${ElseIf} $0 != 0
    ${AndIf} $0 != 1638
      MessageBox MB_OK|MB_ICONEXCLAMATION "Microsoft Visual C++ no terminó de instalarse. Código de salida: $0."
    ${EndIf}
  ${EndIf}
  !insertmacro setDesktopInstallStatus "Finalizando la instalación de Hostal Rincón..."
!macroend
