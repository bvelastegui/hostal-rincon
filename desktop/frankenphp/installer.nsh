!include "${PROJECT_DIR}/installer.nsh"

!macro customHeader
  ShowInstDetails show
!macroend

!macro customCheckAppRunning
  SetDetailsPrint listonly
  DetailPrint "Verificando la instalación..."
  ${If} ${FileExists} "$INSTDIR\${APP_EXECUTABLE_FILENAME}"
    checkHostalProcess:
    ${nsProcess::FindProcess} "${APP_EXECUTABLE_FILENAME}" $R0
    ${nsProcess::Unload}
    ${If} $R0 == 0
      MessageBox MB_RETRYCANCEL|MB_ICONEXCLAMATION "$(appCannotBeClosed)" /SD IDCANCEL IDRETRY checkHostalProcess
      Quit
    ${EndIf}
  ${EndIf}
  DetailPrint "Copiando los archivos de la aplicación..."
!macroend
