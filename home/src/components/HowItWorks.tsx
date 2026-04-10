import React from 'react';
import { motion } from 'framer-motion';
export function HowItWorks() {
  const steps = [
  {
    num: '1',
    title: 'Register',
    desc: 'Create your account in seconds. No credit card needed to start your 30-day trial.'
  },
  {
    num: '2',
    title: 'Set up your restaurant',
    desc: 'Add your locations, configure operating hours, and invite your staff members.'
  },
  {
    num: '3',
    title: 'Start taking bookings',
    desc: 'Manage reservations from any device, in real time. Say goodbye to paper logs.'
  }];

  return (
    <section className="py-24 bg-cream">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-20">
          <h2 className="text-3xl md:text-4xl font-bold text-forest mb-4">
            Up and running in minutes
          </h2>
          <p className="text-lg text-forest/70">
            No technical setup, no app installation required.
          </p>
        </div>

        <div className="relative">
          {/* Connecting line for desktop */}
          <div className="hidden md:block absolute top-8 left-[10%] right-[10%] h-0.5 bg-sage-light z-0"></div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-12 relative z-10">
            {steps.map((step, index) =>
            <motion.div
              key={index}
              initial={{
                opacity: 0,
                y: 20
              }}
              whileInView={{
                opacity: 1,
                y: 0
              }}
              viewport={{
                once: true
              }}
              transition={{
                delay: index * 0.2,
                duration: 0.5
              }}
              className="text-center flex flex-col items-center">
              
                <div className="w-16 h-16 bg-forest text-cream rounded-full flex items-center justify-center text-2xl font-bold mb-6 shadow-lg shadow-forest/20 border-4 border-cream">
                  {step.num}
                </div>
                <h3 className="text-xl font-bold text-forest mb-3">
                  {step.title}
                </h3>
                <p className="text-forest/70 leading-relaxed max-w-xs">
                  {step.desc}
                </p>
              </motion.div>
            )}
          </div>
        </div>
      </div>
    </section>);

}