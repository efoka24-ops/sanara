; Page ajoutée à l'assistant (maquette D2) : lancement au démarrage de Windows, au choix.
!include nsDialogs.nsh
!include LogicLib.nsh

!define RUN_KEY "Software\Microsoft\Windows\CurrentVersion\Run"

!ifndef BUILD_UNINSTALLER
  Var StartupCheckbox
  Var StartupChoice

  !macro customPageAfterChangeDir
    Page custom StartupPageCreate StartupPageLeave

    Function StartupPageCreate
      !insertmacro MUI_HEADER_TEXT "Démarrage" "Choisissez comment Sanara se lance."
      nsDialogs::Create 1018
      Pop $0
      ${NSD_CreateLabel} 0 0 100% 24u "Vos ventes restent disponibles même sans internet."
      Pop $0
      ${NSD_CreateCheckbox} 0 34u 100% 12u "Lancer Sanara au démarrage de Windows"
      Pop $StartupCheckbox
      ${If} $StartupChoice == ${BST_CHECKED}
        ${NSD_Check} $StartupCheckbox
      ${EndIf}
      nsDialogs::Show
    FunctionEnd

    Function StartupPageLeave
      ${NSD_GetState} $StartupCheckbox $StartupChoice
    FunctionEnd
  !macroend

  !macro customInstall
    ${If} $StartupChoice == ${BST_CHECKED}
      WriteRegStr HKCU "${RUN_KEY}" "Sanara" '"$INSTDIR\${APP_EXECUTABLE_FILENAME}"'
    ${EndIf}
  !macroend
!endif

!macro customUnInstall
  ; Une mise à jour désinstalle puis réinstalle : le choix est conservé.
  ${ifNot} ${isUpdated}
    DeleteRegValue HKCU "${RUN_KEY}" "Sanara"
  ${endIf}
!macroend
