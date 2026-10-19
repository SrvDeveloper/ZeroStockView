/** ログインパスワードの条件。サーバーの検証（半角英数字記号12文字以上）と同じにする */
export const PASSWORD_MIN_LENGTH = 12;
const PASSWORD_PATTERN = /^[\x21-\x7e]+$/;

export interface PasswordRule {
    label: string;
    valid: boolean;
}

export function passwordRules(password: string): PasswordRule[] {
    return [
        { label: `${PASSWORD_MIN_LENGTH}文字以上`, valid: password.length >= PASSWORD_MIN_LENGTH },
        { label: "半角英数字記号のみ", valid: password.length > 0 && PASSWORD_PATTERN.test(password) },
    ];
}

export function isValidPassword(password: string): boolean {
    return passwordRules(password).every((rule) => rule.valid);
}
