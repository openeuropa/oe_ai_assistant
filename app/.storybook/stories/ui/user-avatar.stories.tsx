import type { Meta, StoryObj } from "@storybook/react-vite";
import {
  AvatarStack,
  avatarColorClass,
  UserAvatar,
} from "../../../src/components/ui/user-avatar";

/** Five sample participants; the first one is the current user. */
const participants = [
  "Dev Editor",
  "Maria Rossi",
  "Jan Kowalski",
  "Ana Silva",
  "Peter Novak",
];

const meta = {
  title: "UI/User avatar",
  component: UserAvatar,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof UserAvatar>;

export default meta;
type Story = StoryObj<typeof meta>;

/** One avatar per user: colors follow the order of contribution. */
export const Palette: Story = {
  render: () => (
    <div className="flex items-center gap-3">
      {participants.map((name, index) => (
        <UserAvatar
          key={name}
          name={name}
          colorClass={avatarColorClass(index)}
        />
      ))}
    </div>
  ),
};

/**
 * The header stack: overlapped like GitHub contributors, sliding apart
 * on hover; the current user sits first and on top while colors stay
 * bound to contribution order. Hover an avatar for the full name popup.
 */
export const Stack: Story = {
  render: () => (
    <div className="flex justify-end bg-white p-6">
      <AvatarStack
        items={participants.map((name, index) => ({
          name,
          colorClass: avatarColorClass(index),
        }))}
      />
    </div>
  ),
};
