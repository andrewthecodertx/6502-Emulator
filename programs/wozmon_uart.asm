;  The WOZ Monitor for the Apple 1
;  Written by Steve Wozniak in 1976
;  Adapted for UART by Andrew S. Erwin
;  Inspired by Ben Eater's 6502 breadboard computer

; Page 0 Variables (UNCHANGED from original)

XAML            = $24           ;  Last "opened" location Low
XAMH            = $25           ;  Last "opened" location High
STL             = $26           ;  Store address Low
STH             = $27           ;  Store address High
L               = $28           ;  Hex value parsing Low
H               = $29           ;  Hex value parsing High
YSAV            = $2A           ;  Used to see if hex value is given
MODE            = $2B           ;  $00=XAM, $7F=STOR, $AE=BLOCK XAM

; Input buffer (CHANGED: moved to avoid conflict with code at $0200)
IN              = $0400         ;  Input buffer to $047F

; Hardware addresses - CHANGED from Apple 1 PIA to W65C51N UART
; Original Apple 1 hardware:
;KBD             = $D010         ;  PIA.A keyboard input
;KBDCR           = $D011         ;  PIA.A keyboard control register
;DSP             = $D012         ;  PIA.B display output register
;DSPCR           = $D013         ;  PIA.B display control register

; NEW: W65C51N UART registers for our system
UART_DATA       = $FE00         ; Data register (R/W)
UART_STATUS     = $FE01         ; Status register (Read only)
UART_COMMAND    = $FE02         ; Command register (Write only)
UART_CONTROL    = $FE03         ; Control register (Write only)

; NEW: UART Status bits
RX_READY        = $08           ; Receiver data ready
TX_EMPTY        = $10           ; Transmitter data register empty

; CHANGED: Load address from $FF00 to $0200 for RAM loading
                .org $0200
; REMOVED: .export RESET (not needed for user program)

RESET:          CLD             ; Clear decimal arithmetic mode.
                CLI

; CHANGED: Replace Apple 1 PIA initialization with UART initialization
; Original PIA setup:
;                LDY #$7F        ; Mask for DSP data direction register.
;                STY DSP         ; Set it up.
;                LDA #$A7        ; KBD and DSP control register mask.
;                STA KBDCR       ; Enable interrupts, set CA1, CB1, for
;                STA DSPCR       ;  positive edge sense/output mode.

; NEW: W65C51N UART initialization (match BIOS settings)
                LDA #$1F        ; Reset command
                STA UART_COMMAND
                LDA #$0B        ; No parity, 1 stop bit, 8 data bits
                STA UART_CONTROL
                LDA #$19        ; Enable transmitter/receiver, DTR active
                STA UART_COMMAND

; ADDED: Jump to proper entry point (unlike Apple 1, we need explicit jump)
                JMP ESCAPE      ; Start with prompt

; CHANGED: Character comparisons - remove high bit ($80) used by Apple 1
NOTCR:          CMP #'_'        ; "_"? (was #'_'+$80)
                BEQ BACKSPACE   ; Yes.
                CMP #$1B        ; ESC? (was #$9B, now standard ASCII ESC)
                BEQ ESCAPE      ; Yes.
                INY             ; Advance text index.
                BPL NEXTCHAR    ; Auto ESC if > 127.

; CHANGED: Remove high bit from prompt character
ESCAPE:         LDA #'\'        ; "\" (was #'\'+$80)
                JSR ECHO        ; Output it.
; ADDED: Space after prompt for better cursor positioning (original had no space)
                LDA #' '        ; Space (original had no space)
                JSR ECHO        ; Output it.

; CHANGED: Use standard ASCII CR and add LF for proper line formatting
GETLINE:
; ADDED: LF before prompt for proper cursor positioning (original only had CR)
                LDA #$0A        ; LF
                JSR ECHO        ; Output it.
                LDA #$0D        ; CR (was #$8D)
                JSR ECHO        ; Output it.
                LDY #$01        ; Initialize text index.
BACKSPACE:      DEY             ; Back up text index.
                BMI GETLINE     ; Beyond start of line, reinitialize.

; CHANGED: Replace Apple 1 keyboard input with UART input
NEXTCHAR:
; Original Apple 1 keyboard reading:
;                LDA KBDCR       ; Key ready?
;                BPL NEXTCHAR    ; Loop until ready.
;                LDA KBD         ; Load character. B7 should be '1'.

; NEW: UART input reading
                LDA UART_STATUS ; Check UART status
                AND #RX_READY   ; Receiver ready?
                BEQ NEXTCHAR    ; Loop until ready
                LDA UART_DATA   ; Load character (no high bit set)

                STA IN,Y        ; Add to text buffer.
; CHANGED: Remove echo since terminal already echoes input
;                JSR ECHO        ; Display character. (original)
                ; Removed JSR ECHO to prevent double echo

; CHANGED: Compare with standard ASCII CR or LF (modern terminals send LF)
                CMP #$0D        ; CR? (was #$8D)
                BEQ @enter      ; Yes, process command
                CMP #$0A        ; LF? (modern terminal Enter key)
                BNE NOTCR       ; No, continue input
@enter:
                LDY #$FF        ; Reset text index.
                LDA #$00        ; For XAM mode.
                TAX             ; 0->X.
SETSTOR:        ASL             ; Leaves $7B if setting STOR mode.
SETMODE:        STA MODE        ; $00=XAM, $7B=STOR, $AE=BLOCK XAM.
                JMP BLSKIP      ; Continue processing
; ADDED: Set BLOCK XAM mode ($AE) explicitly for '.' command
SETBLOCKXAM:    LDA #$AE        ; BLOCK XAM mode (was '.'+$80 = $AE in original)
                STA MODE        ; Set mode
BLSKIP:         INY             ; Advance text index.
NEXTITEM:       LDA IN,Y        ; Get character.

; CHANGED: Compare with standard ASCII CR or LF
                CMP #$0D        ; CR? (was #$8D)
                BEQ GETLINE     ; Yes, done this line.
                CMP #$0A        ; LF? (modern terminal)
                BEQ GETLINE     ; Yes, done this line.

; CHANGED: Remove high bit from character comparisons
                CMP #'.'        ; "."? (was #'.'+$80)
                BCC BLSKIP      ; Skip delimiter.
                BEQ SETBLOCKXAM ; Set BLOCK XAM mode.
                CMP #':'        ; ":"? (was #':'+$80)
                BEQ SETSTOR     ; Yes. Set STOR mode.
                CMP #'R'        ; "R"? (was #'R'+$80)
                BEQ RUN         ; Yes. Run user program.
                STX L           ; $00->L.
                STX H           ;  and H.
                STY YSAV        ; Save Y for comparison.

; CHANGED: Hex parsing - use standard ASCII instead of Apple 1's high-bit encoding
NEXTHEX:        LDA IN,Y        ; Get character for hex test.
                EOR #$30        ; Map digits to $0-9 (was #$B0 for high-bit chars)
                CMP #$0A        ; Digit?
                BCC DIG         ; Yes.
                ADC #$88        ; Map letter "A"-"F" to $FA-FF.
                CMP #$FA        ; Hex letter?
                BCC NOTHEX      ; No, character not hex.
DIG:            ASL
                ASL             ; Hex digit to MSD of A.
                ASL
                ASL
                LDX #$04        ; Shift count.
HEXSHIFT:       ASL             ; Hex digit left, MSB to carry.
                ROL L           ; Rotate into LSD.
                ROL H           ; Rotate into MSD's.
                DEX             ; Done 4 shifts?
                BNE HEXSHIFT    ; No, loop.
                INY             ; Advance text index.
                BNE NEXTHEX     ; Always taken. Check next character for hex.
NOTHEX:         CPY YSAV        ; Check if L, H empty (no hex digits).
                BNE @continue   ; No, continue processing
                JMP ESCAPE      ; Yes, generate ESC sequence (was BEQ, changed to JMP for range)
@continue:
                BIT MODE        ; Test MODE byte.
                BVC NOTSTOR     ; B6=0 STOR, 1 for XAM and BLOCK XAM
                LDA L           ; LSD's of hex data.
                STA (STL,X)     ; Store at current 'store index'.
                INC STL         ; Increment store index.
                BNE NEXTITEM    ; Get next item. (no carry).
                INC STH         ; Add carry to 'store index' high order.
TONEXTITEM:     JMP NEXTITEM    ; Get next command item.
RUN:            JMP (XAML)      ; Run at current XAM index.
NOTSTOR:        BMI XAMNEXT     ; B7=0 for XAM, 1 for BLOCK XAM.
                LDX #$02        ; Byte count.
SETADR:         LDA L-1,X       ; Copy hex data to
                STA STL-1,X     ;  'store index'.
                STA XAML-1,X    ; And to 'XAM index'.
                DEX             ; Next of 2 bytes.
                BNE SETADR      ; Loop unless X=0.
NXTPRNT:        BNE PRDATA      ; NE means no address to print.

; CHANGED: Use standard ASCII CR and add LF for proper line formatting
                LDA #$0D        ; CR (was #$8D)
                JSR ECHO        ; Output it.
; ADDED: LF for proper newline (original only had CR)
                LDA #$0A        ; LF (original had no LF)
                JSR ECHO        ; Output it.
                LDA XAMH        ; 'Examine index' high-order byte.
                JSR PRBYTE      ; Output it in hex format.
                LDA XAML        ; Low-order 'examine index' byte.
                JSR PRBYTE      ; Output it in hex format.

; CHANGED: Remove high bit from colon
                LDA #':'        ; ":" (was #':'+$80)
                JSR ECHO        ; Output it.

; CHANGED: Use standard ASCII space
PRDATA:         LDA #$20        ; Space (was #$A0)
                JSR ECHO        ; Output it.
                LDA (XAML,X)    ; Get data byte at 'examine index'.
                JSR PRBYTE      ; Output it in hex format.
XAMNEXT:        STX MODE        ; 0->MODE (XAM mode).
                LDA XAML
                CMP L           ; Compare 'examine index' to hex data.
                LDA XAMH
                SBC H
                BCS TONEXTITEM  ; Not less, so no more data to output.
                INC XAML
                BNE MOD8CHK     ; Increment 'examine index'.
                INC XAMH
MOD8CHK:        LDA XAML        ; Check low-order 'examine index' byte
                AND #$07        ;  For MOD 8=0
                BPL NXTPRNT     ; Always taken.
PRBYTE:         PHA             ; Save A for LSD.
                LSR
                LSR
                LSR             ; MSD to LSD position.
                LSR
                JSR PRHEX       ; Output hex digit.
                PLA             ; Restore A.

; CHANGED: Remove high bit from ASCII '0'
PRHEX:          AND #$0F        ; Mask LSD for hex print.
                ORA #'0'        ; Add "0" (was #'0'+$80)
                CMP #$3A        ; Digit? (was #$BA)
                BCC ECHO        ; Yes, output it.
                ADC #$06        ; Add offset for letter.

; CHANGED: Replace Apple 1 display output with UART output
ECHO:
; Original Apple 1 display:
;                BIT DSP         ; DA bit (B7) cleared yet?
;                BMI ECHO        ; No, wait for display.
;                STA DSP         ; Output character. Sets DA.

; NEW: UART output with transmitter ready check
                PHA             ; Save character
ECHO_WAIT:      LDA UART_STATUS ; Check UART status
                AND #TX_EMPTY   ; Transmitter ready?
                BEQ ECHO_WAIT   ; No, wait
                PLA             ; Restore character
                STA UART_DATA   ; Output character
                RTS             ; Return.

; REMOVED: Original interrupt vectors (handled by BIOS in our system)
;                BRK             ; unused
;                BRK             ; unused
;
;; Interrupt Vectors
;                .WORD $0F00     ; NMI
;                .WORD RESET     ; RESET
;                .WORD $0000     ; BRK/IRQ
