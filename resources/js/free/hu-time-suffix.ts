// Hungarian vowel harmony for the "-tól/-től" ("from") suffix attached to a
// spoken number (0-59, as used for clock times/minutes). The suffix is decided
// by the vowel class of the *last spoken word* of the number: for a bare unit
// or a compound (11, 32, 47, ...) that's the units word (egy, kettő, három, ...);
// for a round ten (10, 20, 30, 40, 50) there's no units word, so the tens word
// itself decides.
export type HuVowelClass = 'back' | 'front';

// nulla, egy, kettő, három, négy, öt, hat, hét, nyolc, kilenc
const UNIT_CLASS: readonly HuVowelClass[] = ['back', 'front', 'front', 'back', 'front', 'front', 'back', 'front', 'back', 'front'];

// (no tens word for 0), tíz, húsz, harminc, negyven, ötven
const TENS_CLASS: readonly HuVowelClass[] = ['back', 'front', 'back', 'back', 'front', 'front'];

export function huFromSuffixClass(n: number): HuVowelClass {
  if (!Number.isInteger(n) || n < 0 || n > 59) {
    throw new RangeError(`huFromSuffixClass: expected an integer 0-59, got ${n}`);
  }
  const tens = Math.floor(n / 10);
  const units = n % 10;
  return units !== 0 || tens === 0 ? UNIT_CLASS[units]! : TENS_CLASS[tens]!;
}

export function huFromSuffix(n: number): 'tól' | 'től' {
  return huFromSuffixClass(n) === 'back' ? 'tól' : 'től';
}
