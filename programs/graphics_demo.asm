; Graphics Demo for PHP-6502 Emulator
; Demonstrates VideoMemory and ANSIRenderer capabilities
;
; Memory Map:
; $0400-$F3FF: Video Memory (256x240 pixels, 61,440 bytes)
; $0200-$03FF: Program space in RAM
;
; This demo draws various animated patterns to video memory

.setcpu "6502"

; Video memory constants
VIDEO_START_LO = $00
VIDEO_START_HI = $04
VIDEO_WIDTH    = 256
VIDEO_HEIGHT   = 240

; Zero page variables
ZP_X           = $10    ; Current X coordinate
ZP_Y           = $11    ; Current Y coordinate
ZP_COLOR       = $12    ; Current color
ZP_FRAME       = $13    ; Frame counter
ZP_PTR_LO      = $14    ; Video memory pointer low
ZP_PTR_HI      = $15    ; Video memory pointer high
ZP_TEMP        = $16    ; Temporary storage
ZP_PATTERN     = $17    ; Current pattern selector

.segment "CODE"
.org $0200

;-----------------------------------------------------------------------------
; MAIN - Program entry point
;-----------------------------------------------------------------------------
MAIN:
    ; Initialize system
    SEI                 ; Disable interrupts
    CLD                 ; Clear decimal mode

    ; Initialize variables
    LDA #$00
    STA ZP_FRAME
    STA ZP_PATTERN

    ; Clear screen first
    JSR CLEAR_SCREEN

    ; Main loop - cycle through different patterns
MAIN_LOOP:
    ; Pattern 1: Horizontal gradient
    LDA #$00
    STA ZP_PATTERN
    LDX #60             ; Display for 60 frames
@pattern1:
    JSR DRAW_HORIZONTAL_GRADIENT
    JSR DELAY
    DEX
    BNE @pattern1

    ; Pattern 2: Vertical gradient
    LDA #$01
    STA ZP_PATTERN
    LDX #60
@pattern2:
    JSR DRAW_VERTICAL_GRADIENT
    JSR DELAY
    DEX
    BNE @pattern2

    ; Pattern 3: Checkerboard
    LDA #$02
    STA ZP_PATTERN
    LDX #60
@pattern3:
    JSR DRAW_CHECKERBOARD
    JSR DELAY
    DEX
    BNE @pattern3

    ; Pattern 4: Animated plasma effect
    LDA #$03
    STA ZP_PATTERN
    LDX #120            ; Display longer for animation
@pattern4:
    JSR DRAW_PLASMA
    JSR DELAY
    INC ZP_FRAME
    DEX
    BNE @pattern4

    ; Pattern 5: Radial gradient
    LDA #$04
    STA ZP_PATTERN
    LDX #60
@pattern5:
    JSR DRAW_RADIAL
    JSR DELAY
    DEX
    BNE @pattern5

    ; Pattern 6: Color bars
    LDA #$05
    STA ZP_PATTERN
    LDX #60
@pattern6:
    JSR DRAW_COLOR_BARS
    JSR DELAY
    DEX
    BNE @pattern6

    JMP MAIN_LOOP       ; Loop forever

;-----------------------------------------------------------------------------
; CLEAR_SCREEN - Fill entire video memory with black (0)
;-----------------------------------------------------------------------------
CLEAR_SCREEN:
    LDA #VIDEO_START_LO
    STA ZP_PTR_LO
    LDA #VIDEO_START_HI
    STA ZP_PTR_HI

    LDY #$00
    LDA #$00            ; Black color

    ; Clear all 61,440 bytes (240 pages)
    LDX #240            ; 240 pages of 256 bytes
@page_loop:
    LDY #$00
@byte_loop:
    STA (ZP_PTR_LO),Y
    INY
    BNE @byte_loop

    INC ZP_PTR_HI       ; Next page
    DEX
    BNE @page_loop

    RTS

;-----------------------------------------------------------------------------
; DRAW_HORIZONTAL_GRADIENT - Gradient from left (black) to right (white)
;-----------------------------------------------------------------------------
DRAW_HORIZONTAL_GRADIENT:
    LDA #VIDEO_START_LO
    STA ZP_PTR_LO
    LDA #VIDEO_START_HI
    STA ZP_PTR_HI

    LDX #240            ; Height in rows
@row_loop:
    LDY #$00            ; X coordinate (0-255)
@pixel_loop:
    TYA                 ; Use X coordinate as color (0-255)
    STA (ZP_PTR_LO),Y
    INY
    BNE @pixel_loop

    INC ZP_PTR_HI       ; Next row
    DEX
    BNE @row_loop

    RTS

;-----------------------------------------------------------------------------
; DRAW_VERTICAL_GRADIENT - Gradient from top (black) to bottom (white)
;-----------------------------------------------------------------------------
DRAW_VERTICAL_GRADIENT:
    LDA #VIDEO_START_LO
    STA ZP_PTR_LO
    LDA #VIDEO_START_HI
    STA ZP_PTR_HI

    LDX #$00            ; Y coordinate counter (0-239)
@row_loop:
    ; Calculate color based on Y (scale to 0-255)
    TXA
    STA ZP_COLOR

    LDY #$00
@pixel_loop:
    LDA ZP_COLOR
    STA (ZP_PTR_LO),Y
    INY
    BNE @pixel_loop

    INC ZP_PTR_HI       ; Next row
    INX
    CPX #240            ; All 240 rows done?
    BNE @row_loop

    RTS

;-----------------------------------------------------------------------------
; DRAW_CHECKERBOARD - Classic checkerboard pattern
;-----------------------------------------------------------------------------
DRAW_CHECKERBOARD:
    LDA #VIDEO_START_LO
    STA ZP_PTR_LO
    LDA #VIDEO_START_HI
    STA ZP_PTR_HI

    LDX #240            ; Height
@row_loop:
    ; Determine if this is an even or odd row
    TXA
    AND #$10            ; Check bit 4 for larger squares
    STA ZP_TEMP

    LDY #$00
@pixel_loop:
    TYA
    AND #$10            ; Check bit 4 of X coordinate
    EOR ZP_TEMP         ; XOR with row pattern
    BEQ @black
    LDA #$FF            ; White
    JMP @draw
@black:
    LDA #$00            ; Black
@draw:
    STA (ZP_PTR_LO),Y
    INY
    BNE @pixel_loop

    INC ZP_PTR_HI
    DEX
    BNE @row_loop

    RTS

;-----------------------------------------------------------------------------
; DRAW_PLASMA - Animated plasma effect using sine approximation
;-----------------------------------------------------------------------------
DRAW_PLASMA:
    LDA #VIDEO_START_LO
    STA ZP_PTR_LO
    LDA #VIDEO_START_HI
    STA ZP_PTR_HI

    LDX #240            ; Height
    STX ZP_Y
@row_loop:
    LDY #$00
@pixel_loop:
    ; Simple plasma: color = (x XOR y) + frame
    TYA                 ; X coordinate
    EOR ZP_Y            ; XOR with Y
    CLC
    ADC ZP_FRAME        ; Add frame for animation
    STA (ZP_PTR_LO),Y

    INY
    BNE @pixel_loop

    INC ZP_PTR_HI
    DEC ZP_Y
    LDX ZP_Y
    BNE @row_loop

    RTS

;-----------------------------------------------------------------------------
; DRAW_RADIAL - Radial gradient from center
;-----------------------------------------------------------------------------
DRAW_RADIAL:
    LDA #VIDEO_START_LO
    STA ZP_PTR_LO
    LDA #VIDEO_START_HI
    STA ZP_PTR_HI

    LDX #240            ; Height
    STX ZP_Y
@row_loop:
    LDY #$00
@pixel_loop:
    ; Approximate distance from center: abs(x-128) + abs(y-120)
    ; X distance
    TYA
    SEC
    SBC #128
    BCS @x_pos
    EOR #$FF            ; Negate if negative
    CLC
    ADC #$01
@x_pos:
    STA ZP_TEMP

    ; Y distance
    LDA ZP_Y
    SEC
    SBC #120
    BCS @y_pos
    EOR #$FF            ; Negate if negative
    CLC
    ADC #$01
@y_pos:
    CLC
    ADC ZP_TEMP         ; Add X and Y distances
    STA (ZP_PTR_LO),Y

    INY
    BNE @pixel_loop

    INC ZP_PTR_HI
    DEC ZP_Y
    LDX ZP_Y
    BNE @row_loop

    RTS

;-----------------------------------------------------------------------------
; DRAW_COLOR_BARS - Vertical color bars (SMPTE-style)
;-----------------------------------------------------------------------------
DRAW_COLOR_BARS:
    LDA #VIDEO_START_LO
    STA ZP_PTR_LO
    LDA #VIDEO_START_HI
    STA ZP_PTR_HI

    LDX #240            ; Height
@row_loop:
    LDY #$00
@pixel_loop:
    ; 8 color bars, each 32 pixels wide
    TYA
    LSR A               ; Divide by 32
    LSR A
    LSR A
    LSR A
    LSR A
    TAX
    LDA COLOR_BAR_TABLE,X
    STA (ZP_PTR_LO),Y

    INY
    BNE @pixel_loop

    INC ZP_PTR_HI
    DEX
    BNE @row_loop

    RTS

;-----------------------------------------------------------------------------
; DELAY - Small delay between frames
;-----------------------------------------------------------------------------
DELAY:
    LDX #$08            ; Outer loop
@outer:
    LDY #$00            ; Inner loop
@inner:
    DEY
    BNE @inner
    DEX
    BNE @outer
    RTS

;-----------------------------------------------------------------------------
; Data tables
;-----------------------------------------------------------------------------
COLOR_BAR_TABLE:
    .byte 255           ; White (ANSI 255)
    .byte 226           ; Yellow (ANSI 226)
    .byte 51            ; Cyan (ANSI 51)
    .byte 46            ; Green (ANSI 46)
    .byte 201           ; Magenta (ANSI 201)
    .byte 196           ; Red (ANSI 196)
    .byte 21            ; Blue (ANSI 21)
    .byte 0             ; Black (ANSI 0)
