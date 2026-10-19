import axios from "axios";
import type { UserRole } from "@/constants/roles";

export interface ManagedUser {
    id: string;
    loginId: string;
    role: UserRole;
}

export interface UserInput {
    loginId: string;
    password: string;
    passwordConfirmation: string;
    role: UserRole;
}

function payload(input: UserInput) {
    return {
        login_id: input.loginId,
        password: input.password,
        password_confirmation: input.passwordConfirmation,
        role: input.role,
    };
}

export async function fetchUsers(): Promise<ManagedUser[]> {
    const { data } = await axios.get<{ users: ManagedUser[] }>("/api/users");
    return data.users;
}

export async function createUser(input: UserInput): Promise<ManagedUser> {
    const { data } = await axios.post<{ user: ManagedUser }>("/api/users", payload(input));
    return data.user;
}

export async function updateUser(id: string, input: UserInput): Promise<ManagedUser> {
    const { data } = await axios.put<{ user: ManagedUser }>(`/api/users/${id}`, payload(input));
    return data.user;
}

export async function deleteUser(id: string): Promise<void> {
    await axios.delete(`/api/users/${id}`);
}
