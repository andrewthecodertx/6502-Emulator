<?php

namespace Emulator;

enum AddressingMode
{
    case Implied;
    case Accumulator;
    case Immediate;
    case Absolute;
    case X_Indexed_Absolute;
    case Y_Indexed_Absolute;
    case Absolute_Indirect;
    case Zero_Page;
    case X_Indexed_Zero_Page;
    case Y_Indexed_Zero_Page;
    case X_Indexed_Zero_Page_Indirect;
    case Zero_Page_Indirect_Y_Indexed;
    case Relative;
    case Unknown;
}
